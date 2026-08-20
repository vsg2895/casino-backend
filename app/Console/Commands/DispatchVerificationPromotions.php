<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendVerificationPromotionJob;
use App\Models\Newsletter;
use App\Models\Unsubscribe;
use App\Models\VerificationPromotionEmail;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Finds subscribers who are now eligible for the post-verification promotion and
 * queues one job each.
 *
 * WHY A SWEEP rather than a delayed job dispatched from the verify endpoint:
 * the eligibility rule depends on a setting the admin can change at any time,
 * and a job delayed by the OLD value would fire at the wrong moment. A sweep
 * also survives what a delayed job does not — a dropped queue, a worker restart,
 * a subscriber who verifies long after the delay has already elapsed — and it
 * keeps the verify request itself doing nothing but a flag update. This mirrors
 * `promotions:dispatch-due`, which schedules campaigns the same way.
 *
 * THE TIMING RULE. Eligibility is
 *
 *     newsletters.verified_at + delay_minutes <= now
 *
 * i.e. measured from when the subscriber CLICKED THE VERIFY LINK, not from when
 * they subscribed. Someone who subscribes at 10:00 and verifies at 10:50, with a
 * 60-minute delay, is eligible at 11:50 — the clock starts at the click.
 *
 * A NULL verified_at is never eligible. That covers subscribers who have not
 * confirmed, and also every row that predates the column: they are deliberately
 * not backfilled, so switching this feature on cannot blast the existing list.
 *
 * Claiming is NOT done here. This command only selects candidates; the job
 * claims each one atomically, so two overlapping runs cannot double-send.
 */
class DispatchVerificationPromotions extends Command
{
    protected $signature = 'promotions:dispatch-verification
                            {--limit= : Maximum subscribers to queue this run (default: config)}';

    protected $description = 'Queue the global post-verification promotion for newly eligible subscribers';

    public function handle(): int
    {
        $config = VerificationPromotionEmail::current();

        if (! $config->active) {
            $this->info('Post-verification promotion is disabled — nothing to do.');

            return self::SUCCESS;
        }

        // Cut-off: a subscriber who verified at or before this instant has
        // served their delay. Comparing verified_at against a precomputed
        // timestamp keeps the column bare, so the index is usable.
        $cutoff = Carbon::now()->subMinutes(max(0, (int) $config->delay_minutes));
        $limit = (int) ($this->option('limit') ?? config('promotions.verification_dispatch_limit', 1000));

        $candidates = Newsletter::query()
            ->whereNull('verification_promotion_sent_at')   // never claimed
            ->whereNotNull('verified_at')                   // actually clicked the link
            ->where('verified', true)                       // defensive: flag agrees
            ->where('verified_at', '<=', $cutoff)           // delay since the click has elapsed
            // Honour the promotion-stream opt-out, exactly as the batch job does.
            ->whereNotExists(function (Builder $query): void {
                $query->from('unsubscribes')
                    ->whereColumn('unsubscribes.email', 'newsletters.email')
                    ->whereColumn('unsubscribes.site_id', 'newsletters.site_id')
                    ->where('unsubscribes.type', Unsubscribe::TYPE_PROMOTION);
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        if ($candidates->isEmpty()) {
            $this->info('No subscribers are eligible right now.');

            return self::SUCCESS;
        }

        foreach ($candidates as $id) {
            SendVerificationPromotionJob::dispatch((int) $id);
        }

        Log::info('Post-verification promotions queued', [
            'count'         => $candidates->count(),
            'delay_minutes' => (int) $config->delay_minutes,
            'cutoff'        => $cutoff->toDateTimeString(),
        ]);

        $this->info("Queued {$candidates->count()} post-verification promotion(s).");

        return self::SUCCESS;
    }
}
