<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TwilioConfig;
use App\Services\PhoneRecipientService;
use App\Support\Phone\PhoneAudienceFilter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fans a bulk SMS run out into recipient BATCHES.
 *
 * The audience comes from {@see PhoneRecipientService} — the SAME resolver behind
 * the admin's count, preview and CSV export — so what an admin previews is
 * precisely who gets messaged. It reads `newsletters_based_on_phone` and nothing
 * else.
 *
 * Dispatches ONE {@see SendSmsBatchJob} per batch of numbers, not one job per
 * number, streaming the audience so the whole list never loads into memory.
 * Runs on the LOW queue: an SMS blast is background traffic and must never delay
 * a subscription confirmation or a list import on `high`.
 *
 * The admin's filters travel with the job as a plain array and are rehydrated
 * here, which is what makes "the selected filters are preserved throughout the
 * sending process" structurally true: there is no second interpretation of them
 * anywhere between the button and the send.
 *
 * NOT auto-retried ($tries = 1). A half-finished fan-out that restarted would
 * re-dispatch batches already in flight, and unlike an email a duplicate SMS
 * costs money and cannot be recalled. A failure is logged loudly for a deliberate
 * re-run.
 */
class SendBulkSmsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'low';

    /** Fallback numbers per batch job when config is unavailable. */
    private const int BATCH_SIZE = 100;

    /** Fallback rows per database round-trip. */
    private const int READ_CHUNK = 500;

    /** One shot: see the class docblock on why this must not auto-retry. */
    public int $tries = 1;

    /**
     * Streaming a large audience and queueing its batches takes far longer than
     * the 60s worker default. MUST stay below the connection's `retry_after`.
     */
    public int $timeout;

    /**
     * @param  array<string, mixed>  $filter     The admin's selected filters, as
     *                                           an array rather than a serialised
     *                                           object so a payload written by one
     *                                           deploy stays readable by the next.
     * @param  string|null           $lockOwner  Owner token of the "run in flight"
     *                                           lock taken by the admin action;
     *                                           released here when the fan-out ends.
     */
    public function __construct(
        public readonly int $twilioConfigId,
        public readonly string $body,
        public readonly array $filter = [],
        public readonly ?string $lockOwner = null,
    ) {
        $this->onQueue(self::ON_QUEUE);
        $this->timeout = (int) config('sms.fan_out_timeout', 900);
    }

    /** Cache key guarding one in-flight bulk run at a time. */
    public static function runLockKey(): string
    {
        return 'phone-newsletter-bulk-sms-run';
    }

    public function handle(PhoneRecipientService $recipients): void
    {
        try {
            $this->fanOut($recipients);
        } finally {
            // Whatever happened, the run is no longer in flight — free it for
            // another send instead of waiting out the TTL.
            $this->releaseRunLock();
        }
    }

    private function fanOut(PhoneRecipientService $recipients): void
    {
        $config = TwilioConfig::find($this->twilioConfigId);

        if ($config === null) {
            Log::warning('Bulk SMS aborted: the Twilio configuration no longer exists', [
                'twilio_config_id' => $this->twilioConfigId,
            ]);

            return;
        }

        // Re-checked here, not just at the button: a credential can be disabled
        // or stripped of its sender between queueing and running, and finding out
        // per recipient would mean one identical failure row per number.
        if (! $config->isActive()) {
            Log::warning('Bulk SMS aborted: the Twilio configuration is inactive', [
                'twilio_config_id' => $config->id,
            ]);

            return;
        }

        if (! $config->hasSender()) {
            Log::error('Bulk SMS aborted: the Twilio configuration has no sender identity', [
                'twilio_config_id' => $config->id,
            ]);

            return;
        }

        $filter = PhoneAudienceFilter::fromArray($this->filter);

        $batchSize = $this->positiveConfig('sms.send_batch_size', self::BATCH_SIZE);
        // Never read less than one batch per round-trip.
        $readChunk = max($batchSize, $this->positiveConfig('sms.read_chunk', self::READ_CHUNK));

        $queued = 0;

        // Resolved ONCE for the whole run: the window is anchored to the moment
        // the fan-out starts, so a run that takes minutes cannot have "today"
        // roll over mid-traversal and start pulling in numbers the admin never
        // previewed.
        $now = now();

        $recipients->eachChunk(
            $filter,
            $readChunk,
            function (Collection $rows) use ($config, $batchSize, &$queued): void {
                // Reduce to a flat list of numbers immediately: the hydrated rows
                // are not needed past this point, and holding them while
                // dispatching would keep a whole read chunk alive.
                $phones = $rows->pluck('phone')->all();
                unset($rows);

                foreach (array_chunk($phones, $batchSize) as $payload) {
                    SendSmsBatchJob::dispatch($payload, $this->body, $config->id);
                    $queued += count($payload);
                }
            },
            $now,
        );

        Log::info('Bulk SMS fanned out', [
            'twilio_config_id' => $config->id,
            'recipients'       => $queued,
            'batch_size'       => $batchSize,
            'filters'          => $filter->describe(),
        ]);
    }

    /** A config value that must be a positive int, falling back when it is not. */
    private function positiveConfig(string $key, int $fallback): int
    {
        $value = (int) config($key, $fallback);

        return $value > 0 ? $value : $fallback;
    }

    /**
     * Release the run lock, if this dispatch owns one. Owner-scoped, so a
     * late-finishing job can never release a lock a newer run has taken.
     */
    private function releaseRunLock(): void
    {
        if ($this->lockOwner === null) {
            return;
        }

        try {
            Cache::restoreLock(self::runLockKey(), $this->lockOwner)->release();
        } catch (Throwable $e) {
            // The lock expires on its own; never fail a completed run over a
            // cache hiccup.
            Log::warning('Could not release the bulk SMS run lock', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Called when the fan-out fails: the run is INCOMPLETE, say so loudly. */
    public function failed(Throwable $e): void
    {
        $this->releaseRunLock();

        Log::error('Bulk SMS fan-out failed; the run may be partially queued', [
            'twilio_config_id' => $this->twilioConfigId,
            'error'            => $e->getMessage(),
        ]);
    }
}
