<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\WarmupSend;
use App\Services\Mail\WarmupMailResolver;
use App\Services\WarmupRecipientService;
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
 * Fans a warmup run out into address BATCHES.
 *
 * Mirrors the SHAPE of {@see SendScheduledPromotionJob} — stream the audience in
 * `read_chunk` rows, slice each read into `batch_size` payloads, dispatch one
 * batch job per slice, hold a run lock, log once at the end — but is its own
 * class. The promotion fan-out is not modified, imported or subclassed by this
 * feature: it is coupled to EmailSchedule, per-schedule providers and the
 * delivery-history dedup, none of which exist here.
 *
 * The two-dimensional sizing is the part worth copying: reading 500 rows per
 * round-trip while dispatching 100-address jobs keeps a large list at few queries
 * without creating slow, hard-to-retry jobs.
 *
 * Runs on the LOW queue: warmup is background traffic and must never delay a
 * subscription confirmation or a list import on `high`.
 *
 * NOT auto-retried ($tries = 1). A half-finished fan-out that restarted would
 * re-dispatch batches already in flight, mailing the same seed addresses twice
 * and skewing the rotation.
 */
class SendWarmupCampaignJob implements ShouldQueue
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

    public int $tries = 1;

    /** Must stay below the queue connection's `retry_after`. */
    public int $timeout;

    /**
     * @param  int|null  $limit  Null means EVERY address on the list.
     */
    public function __construct(
        public readonly int $warmupSendId,
        public readonly int $siteId,
        public readonly string $template,
        public readonly ?int $limit = null,
        public readonly ?string $lockOwner = null,
    ) {
        $this->onQueue(self::ON_QUEUE);
        $this->timeout = (int) config('warmup.fan_out_timeout', 900);
    }

    /** Cache key guarding one in-flight warmup run at a time. */
    public static function runLockKey(): string
    {
        return 'warmup-campaign-run';
    }

    public function handle(WarmupRecipientService $recipients): void
    {
        try {
            $this->fanOut($recipients);
        } finally {
            $this->releaseRunLock();
        }
    }

    private function fanOut(WarmupRecipientService $recipients): void
    {
        $site = Site::find($this->siteId);

        if ($site === null) {
            Log::warning('Warmup run aborted: the site no longer exists', ['site_id' => $this->siteId]);

            return;
        }

        // Re-checked here, not only at the button: a template can be removed from
        // the allow-list between queueing and running, and discovering that per
        // address would mean one identical failure per recipient.
        if (! WarmupMailResolver::supports($this->template)) {
            Log::error('Warmup run aborted: template not permitted for warmup', [
                'site_id'  => $site->id,
                'template' => $this->template,
            ]);

            return;
        }

        $batchSize = $this->positiveConfig('warmup.send_batch_size', self::BATCH_SIZE);
        // Never read less than one batch per round-trip.
        $readChunk = max($batchSize, $this->positiveConfig('warmup.read_chunk', self::READ_CHUNK));

        $queued = 0;

        $recipients->eachChunk($this->limit, $readChunk, function (Collection $rows) use ($batchSize, &$queued): void {
            // Reduce to a flat address list immediately — the hydrated rows are
            // not needed past this point, and holding them while dispatching
            // would keep a whole read chunk alive.
            $emails = $rows->pluck('email')->all();
            unset($rows);

            foreach (array_chunk($emails, $batchSize) as $payload) {
                SendWarmupBatchJob::dispatch($payload, $this->siteId, $this->template);
                $queued += count($payload);
            }
        });

        WarmupSend::query()->whereKey($this->warmupSendId)->update(['queued_count' => $queued]);

        Log::info('Warmup run fanned out', [
            'warmup_send_id' => $this->warmupSendId,
            'site_id'        => $site->id,
            'template'       => $this->template,
            'recipients'     => $queued,
            'batch_size'     => $batchSize,
            'scope'          => $this->limit === null ? 'all' : $this->limit,
        ]);
    }

    private function positiveConfig(string $key, int $fallback): int
    {
        $value = (int) config($key, $fallback);

        return $value > 0 ? $value : $fallback;
    }

    /** Owner-scoped, so a late-finishing run can never free a newer run's lock. */
    private function releaseRunLock(): void
    {
        if ($this->lockOwner === null) {
            return;
        }

        try {
            Cache::restoreLock(self::runLockKey(), $this->lockOwner)->release();
        } catch (Throwable $e) {
            Log::warning('Could not release the warmup run lock', ['error' => $e->getMessage()]);
        }
    }

    public function failed(Throwable $e): void
    {
        $this->releaseRunLock();

        Log::error('Warmup fan-out failed; the run may be partially queued', [
            'warmup_send_id' => $this->warmupSendId,
            'error'          => $e->getMessage(),
        ]);
    }
}
