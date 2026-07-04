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
 * Fans a scheduled promotion campaign out to its recipients.
 *
 * Resolves the schedule's subscriber window (newsletters.created_at within
 * {@see EmailSchedule::dateRange()}), excludes promotion-stream opt-outs, and
 * dispatches one {@see SendPromotionToSubscriberJob} per address (chunked, so a
 * huge list never loads into memory). Runs on the LOW queue.
 */
class SendScheduledPromotionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'low';

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

        // Base recipient set for this site, excluding promotion opt-outs. The
        // opt-out check is a single correlated NOT EXISTS (covered by the
        // unsubscribes unique index) — no per-row lookups, no N+1.
        $recipients = Newsletter::query()
            ->where('site_id', $schedule->site_id)
            ->whereNotExists(function (Builder $query) use ($schedule): void {
                $query->from('unsubscribes')
                    ->whereColumn('unsubscribes.email', 'newsletters.email')
                    ->where('unsubscribes.site_id', $schedule->site_id)
                    ->where('unsubscribes.type', Unsubscribe::TYPE_PROMOTION);
            });

        if ($schedule->usesLimit()) {
            // Newest N subscribers. Bounded by `limit`, index-covered by
            // (site_id, created_at); pluck a single email column and fan out.
            $recipients
                ->orderByDesc('created_at')
                ->limit((int) $schedule->limit)
                ->pluck('email')
                ->each(fn (string $email) => $this->queueOne($schedule->site_id, $email));

            return;
        }

        // Sign-up date window. Potentially unbounded → chunk by id (memory-safe).
        [$start, $end] = $schedule->dateRange(now());

        $recipients
            ->whereBetween('created_at', [$start, $end])
            ->select(['id', 'email'])
            ->chunkById(500, function ($rows) use ($schedule): void {
                foreach ($rows as $row) {
                    $this->queueOne($schedule->site_id, (string) $row->email);
                }
            });
    }

    private function queueOne(int $siteId, string $email): void
    {
        SendPromotionToSubscriberJob::dispatch($siteId, $email);
    }
}
