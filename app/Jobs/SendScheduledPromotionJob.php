<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EmailSchedule;
use App\Models\Newsletter;
use App\Models\Unsubscribe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans a scheduled promotion campaign out into recipient BATCHES.
 *
 * Resolves the schedule's audience (a sign-up date window OR the newest N by
 * created_at), excludes promotion opt-outs in a single correlated NOT EXISTS,
 * and dispatches ONE {@see SendPromotionBatchJob} per BATCH_SIZE addresses —
 * not one job per email. The recipient scan never loads the whole list into
 * memory (chunked / bounded by limit) and only selects the `email` column;
 * per-recipient tokens are fetched inside each batch job. Runs on the LOW queue.
 */
class SendScheduledPromotionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'low';

    /** Addresses per batch job. */
    private const int BATCH_SIZE = 100;

    public function __construct(public readonly int $scheduleId)
    {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(): void
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

        $recipients = Newsletter::query()
            ->where('site_id', $siteId)
            ->whereNotExists(function (Builder $query) use ($siteId): void {
                $query->from('unsubscribes')
                    ->whereColumn('unsubscribes.email', 'newsletters.email')
                    ->where('unsubscribes.site_id', $siteId)
                    ->where('unsubscribes.type', Unsubscribe::TYPE_PROMOTION);
            });

        if ($schedule->usesLimit()) {
            // Newest N (bounded by limit, index-covered by (site_id, created_at)).
            $recipients
                ->orderByDesc('created_at')
                ->limit((int) $schedule->limit)
                ->pluck('email')
                ->chunk(self::BATCH_SIZE)
                ->each(fn ($emails) => SendPromotionBatchJob::dispatch($siteId, $emails->values()->all()));

            return;
        }

        // Sign-up date window: chunk the id cursor so a huge list never loads at
        // once; each chunk becomes one batch job.
        [$start, $end] = $schedule->dateRange(now());

        $recipients
            ->whereBetween('created_at', [$start, $end])
            ->select(['id', 'email'])
            ->chunkById(self::BATCH_SIZE, function ($rows) use ($siteId): void {
                SendPromotionBatchJob::dispatch($siteId, $rows->pluck('email')->all());
            });
    }
}
