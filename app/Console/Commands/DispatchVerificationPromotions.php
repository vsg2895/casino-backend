<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendVerificationPromotionJob;
use App\Models\Newsletter;
use App\Models\VerificationPromotionEmail;
use App\Support\ClockFacts;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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
        // FIRST STATEMENT IN THE METHOD, on purpose. Every other log line here
        // is conditional on getting past some check, so their absence is
        // ambiguous: a stranded `withoutOverlapping` mutex, a cron that stopped,
        // and a sweep that ran and found nobody all produce the same silence.
        // This one line separates "the sweep did not run" from everything else,
        // and carries the clock context that a timing report is always really
        // about — see {@see ClockFacts}.
        Log::info('Post-verification promotion sweep running', ClockFacts::forLog());

        $config = VerificationPromotionEmail::current();

        if (! $config->active) {
            // Logged, not just printed: when nothing arrives in production this
            // is the first thing to rule out, and the scheduler's output goes
            // nowhere by default.
            Log::info('Post-verification promotion sweep skipped: feature is disabled');
            $this->info('Post-verification promotion is disabled — nothing to do.');

            return self::SUCCESS;
        }

        // Cut-off: a subscriber who verified at or before this instant has
        // served their delay. Comparing verified_at against a precomputed
        // timestamp keeps the column bare, so the index is usable.
        $cutoff = Carbon::now()->subMinutes(max(0, (int) $config->delay_minutes));
        $limit = (int) ($this->option('limit') ?? config('promotions.verification_dispatch_limit', 1000));

        $candidates = $this->eligible($cutoff)
            ->orderBy('id')
            ->limit($limit)
            // id AND email: the id is what the job needs, the email is what
            // makes the log line answerable ("did MY address get queued?")
            // without joining back to the table by hand.
            ->get(['id', 'email']);

        if ($candidates->isEmpty()) {
            // "The sweep ran and found nobody" and "the sweep never ran at all"
            // look identical from the outside otherwise, and they have
            // completely different causes.
            //
            // The funnel is computed HERE and nowhere else. It costs a handful
            // of COUNTs, which is not worth paying every minute on the happy
            // path — but this is the exact moment somebody wants to know WHY
            // nobody was picked, so the breakdown is worth its price precisely
            // when the count is zero.
            Log::info('Post-verification promotion sweep found no eligible subscribers', [
                'delay_minutes' => (int) $config->delay_minutes,
                // Both, so the rule is legible without doing the subtraction by
                // hand — and so a `verified_at` copied out of the admin can be
                // compared against a cutoff in the SAME timezone as this line.
                'now'      => Carbon::now()->toDateTimeString(),
                'cutoff'   => $cutoff->toDateTimeString(),
                'audience' => $this->funnel($cutoff),
            ]);
            $this->info('No subscribers are eligible right now.');

            return self::SUCCESS;
        }

        foreach ($candidates as $candidate) {
            SendVerificationPromotionJob::dispatch((int) $candidate->id);
        }

        $emails = $candidates->pluck('email')->implode(', ');

        Log::info('Post-verification promotions queued', [
            'count'         => $candidates->count(),
            'delay_minutes' => (int) $config->delay_minutes,
            'cutoff'        => $cutoff->toDateTimeString(),
            // Whether this run hit the per-run ceiling. If it did, more
            // subscribers are waiting and the next tick picks them up — worth
            // knowing before concluding that a count looks too low.
            'limit'         => $limit,
            'limit_reached' => $candidates->count() === $limit,
            'emails'        => $emails,
        ]);

        $this->info("Queued {$candidates->count()} post-verification promotion(s): {$emails}");

        return self::SUCCESS;
    }

    /**
     * Subscribers who should receive the promotion right now.
     *
     * THE one definition of eligibility — the dispatch query and the funnel's
     * final row are the same builder, so the diagnosis can never describe a
     * different rule from the one that actually runs.
     *
     * @return EloquentBuilder<Newsletter>
     */
    private function eligible(CarbonInterface $cutoff): EloquentBuilder
    {
        return Newsletter::query()
            ->whereNull('verification_promotion_sent_at')   // never claimed
            ->whereNotNull('verified_at')                   // actually clicked the link
            ->where('verified', true)                       // defensive: flag agrees
            ->where('verified_at', '<=', $cutoff)           // delay since the click has elapsed
            // Honour a global opt-out — any template's opt-out excludes the
            // address, exactly as the send job re-checks with Unsubscribe::hasAny.
            ->whereNotExists(function (Builder $query): void {
                $query->from('unsubscribes')
                    ->whereColumn('unsubscribes.email', 'newsletters.email')
                    ->whereColumn('unsubscribes.site_id', 'newsletters.site_id');
            });
    }

    /**
     * How many subscribers survive each condition, in order.
     *
     * Each row is the previous one plus ONE more condition, so the row where the
     * number collapses to zero names the reason nobody is being sent to. That is
     * the whole point: "0 eligible" on its own is not an answer, and reading it
     * off the database by hand at 2am is how mistakes get made.
     *
     * `already_sent` is the one row that is expected to grow — it is everyone
     * who has already received the promotion, which is a success total, not a
     * problem.
     *
     * @return array<string, int>
     */
    private function funnel(CarbonInterface $cutoff): array
    {
        $verified = Newsletter::query()->where('verified', true);

        return [
            'subscribers'      => Newsletter::query()->count(),
            'verified'         => (clone $verified)->count(),
            'with_verified_at' => (clone $verified)->whereNotNull('verified_at')->count(),
            'delay_elapsed'    => (clone $verified)->whereNotNull('verified_at')
                ->where('verified_at', '<=', $cutoff)->count(),
            'already_sent'     => (clone $verified)->whereNotNull('verification_promotion_sent_at')->count(),
            'eligible_now'     => $this->eligible($cutoff)->count(),
        ];
    }
}
