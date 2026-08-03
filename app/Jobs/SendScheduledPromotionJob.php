<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EmailSchedule;
use App\Services\ScheduleRecipientService;
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
 * Fans a scheduled promotion campaign out into recipient BATCHES.
 *
 * The audience (sign-up date window OR newest N, minus promotion opt-outs) is
 * resolved by {@see ScheduleRecipientService} — the single shared definition
 * also used by the admin's recipient preview, count and CSV export, so what an
 * admin previews is precisely who gets mailed.
 *
 * Dispatches ONE {@see SendPromotionBatchJob} per batch of addresses — not one
 * job per email — streaming the audience so the whole list never loads into
 * memory; per-recipient tokens are fetched inside each batch job. Runs on the
 * LOW queue.
 *
 * Sizing is two-dimensional (see config/promotions.php): the database is read
 * in `read_chunk` rows per round-trip, and each read is sliced into payloads of
 * `batch_size`. Decoupling the two is what keeps a 50k audience at ~100 queries
 * instead of ~500 while still dispatching small, quickly-retryable jobs.
 *
 * NOT auto-retried ($tries = 1). A half-finished fan-out that restarts would
 * re-dispatch batches that are already in flight; the 24h history dedup would
 * catch most of it, but "most" is not good enough at this volume. A failure is
 * logged loudly for a deliberate re-run from the admin's "Run now".
 */
class SendScheduledPromotionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'low';

    /** Fallback addresses per batch job when config is unavailable. */
    private const int BATCH_SIZE = 100;

    /** Fallback rows per database round-trip. */
    private const int READ_CHUNK = 500;

    /** One shot: see the class docblock on why this must not auto-retry. */
    public int $tries = 1;

    /**
     * Streaming 50k recipients and queueing their batches takes far longer than
     * the 60s worker default. MUST stay below the connection's `retry_after`.
     */
    public int $timeout;

    /**
     * @param  string|null  $lockOwner  Owner token of the "campaign in flight"
     *                                  lock taken by the admin's Run-now action;
     *                                  released here when the fan-out ends. Null
     *                                  for the scheduler path, which is already
     *                                  serialised by its own minute claim.
     */
    public function __construct(
        public readonly int $scheduleId,
        public readonly ?string $lockOwner = null,
    ) {
        $this->onQueue(self::ON_QUEUE);
        $this->timeout = (int) config('promotions.fan_out_timeout', 900);
    }

    /** Cache key guarding one in-flight fan-out per schedule. */
    public static function runLockKey(int $scheduleId): string
    {
        return 'promotion-schedule-run:' . $scheduleId;
    }

    public function handle(ScheduleRecipientService $recipients): void
    {
        try {
            $this->fanOut($recipients);
        } finally {
            // Whatever happened, the campaign is no longer in flight — free the
            // schedule for another manual run instead of waiting out the TTL.
            $this->releaseRunLock();
        }
    }

    private function fanOut(ScheduleRecipientService $recipients): void
    {
        $schedule = EmailSchedule::with('site')->find($this->scheduleId);

        if ($schedule === null || ! $schedule->active || $schedule->site === null) {
            return;
        }

        // Respect the per-site promotion toggle up front — nothing to fan out.
        if (! $schedule->site->promotionEmailOrDefault()->active) {
            return;
        }

        $siteId = $schedule->site_id;

        // Delivery transport is per-schedule. Only the provider name + the key ID
        // travel into the batch jobs — never the decrypted key (which is resolved
        // and re-validated inside each batch job at send time).
        $provider = $schedule->provider ?? EmailSchedule::PROVIDER_SMTP;
        $sendgridKeyId = $schedule->sendgrid_key_id;

        $batchSize = $this->positiveConfig('promotions.batch_size', self::BATCH_SIZE);
        // Never read less than one batch per round-trip.
        $readChunk = max($batchSize, $this->positiveConfig('promotions.read_chunk', self::READ_CHUNK));

        $queued = 0;

        // The audience comes from the shared resolver — the SAME query the admin
        // preview, count and CSV export use, so what an admin previews is
        // exactly who gets mailed. Streamed in chunks: the full list is never
        // held in memory, and each chunk is sliced into batch jobs.
        $recipients->eachChunk(
            $schedule,
            $readChunk,
            function (Collection $rows) use ($siteId, $provider, $sendgridKeyId, $batchSize, &$queued): void {
                // Reduce the chunk to a flat address list immediately: the
                // hydrated rows are not needed past this point and holding them
                // while dispatching would keep a whole read chunk alive.
                $emails = $rows->pluck('email')->all();
                unset($rows);

                foreach (array_chunk($emails, $batchSize) as $payload) {
                    SendPromotionBatchJob::dispatch($siteId, $payload, $provider, $sendgridKeyId);
                    $queued += count($payload);
                }
            },
        );

        Log::info('Promotion campaign fanned out', [
            'schedule_id' => $this->scheduleId,
            'site_id'     => $siteId,
            'recipients'  => $queued,
            'batch_size'  => $batchSize,
        ]);
    }

    /** A config value that must be a positive int, falling back when it is not. */
    private function positiveConfig(string $key, int $fallback): int
    {
        $value = (int) config($key, $fallback);

        return $value > 0 ? $value : $fallback;
    }

    /**
     * Release the Run-now lock, if this dispatch owns one. Owner-scoped, so a
     * late-finishing job can never release a lock a newer run has taken.
     */
    private function releaseRunLock(): void
    {
        if ($this->lockOwner === null) {
            return;
        }

        try {
            Cache::restoreLock(self::runLockKey($this->scheduleId), $this->lockOwner)->release();
        } catch (Throwable $e) {
            // The lock expires on its own; never fail a completed campaign over
            // a cache hiccup.
            Log::warning('Could not release promotion run lock', [
                'schedule_id' => $this->scheduleId,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /** Called when the fan-out fails: the campaign is INCOMPLETE, say so loudly. */
    public function failed(Throwable $e): void
    {
        $this->releaseRunLock();

        Log::error('Promotion campaign fan-out failed; campaign may be partially queued', [
            'schedule_id' => $this->scheduleId,
            'error'       => $e->getMessage(),
        ]);
    }
}
