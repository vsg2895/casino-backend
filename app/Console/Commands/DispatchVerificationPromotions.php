<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendVerificationPromotionJob;
use App\Jobs\SendRegistrationPromotionJob;
use App\Models\Newsletter;
use App\Models\ForumUser;
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

        $config = VerificationPromotionEmail::current();

        if (! $config->active) {
            // Logged, not just printed: when nothing arrives in production this
            // is the first thing to rule out, and the scheduler's output goes
            // nowhere by default.
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
            $this->info('No subscribers are eligible right now.');
        } else {
            foreach ($candidates as $candidate) {
                SendVerificationPromotionJob::dispatch((int) $candidate->id);
            }

            $emails = $candidates->pluck('email')->implode(', ');

            $this->info("Queued {$candidates->count()} post-verification promotion(s): {$emails}");
        }

        /*
         * THE MEMBER SWEEP RUNS UNCONDITIONALLY.
         *
         * This used to sit after an early `return` taken when no SUBSCRIBER was
         * eligible — so registered members were only ever dispatched in a run
         * where a newsletter subscriber happened to be due at the same minute.
         * With no pending subscribers, which is the normal state, the
         * registration promotion never went out at all.
         *
         * It presented exactly as "the newsletter flow works and the
         * registration flow does not", because the newsletter flow IS the one
         * that gates the other. The two audiences are independent and must be
         * swept independently; the only thing they share is the cut-off
         * computed above, which is the whole reason they live in one command.
         */
        $this->dispatchMembers($cutoff, $limit);

        return self::SUCCESS;
    }

    /**
     * The SECOND audience: members who verified their account registration.
     *
     * Same template, same settings, same cut-off — the delay is computed once,
     * above, and both sweeps read it, so "two minutes after verification" cannot
     * come to mean two different things depending on how somebody signed up.
     *
     * In the same command rather than a second one for exactly that reason. A
     * sibling command would need its own copy of the config read, the cut-off
     * and the disabled check, and the first time somebody changed the delay
     * semantics they would change one of them.
     *
     * The member sweep runs AFTER the subscriber sweep deliberately. Someone who
     * both subscribed and registered with one address should be attributed to
     * the subscription, which is the explicit opt-in; the job's cross-audience
     * gate then sees the subscriber's send record and skips the member.
     */
    private function dispatchMembers(CarbonInterface $cutoff, int $limit): void
    {
        $members = $this->eligibleMembers($cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'email']);

        if ($members->isEmpty()) {
            $this->info('No registered members are eligible right now.');

            return;
        }

        foreach ($members as $member) {
            SendRegistrationPromotionJob::dispatch((int) $member->id);
        }

        $emails = $members->pluck('email')->implode(', ');

        $this->info("Queued {$members->count()} registration promotion(s): {$emails}");
    }

    /**
     * Members who should receive the promotion right now.
     *
     * The mirror of {@see self::eligible()}, against `forum_users`:
     *
     *     email_verified_at + delay_minutes <= now
     *
     * `email_verified_at` is stamped by ForumAuthController::verify() when the
     * member clicks the link in their registration email — so the clock starts
     * at the click, exactly as the subscriber's does. A member who registered
     * but never verified is never eligible, and neither is one already claimed.
     *
     * Banned accounts are excluded. Sending marketing to somebody who has been
     * thrown off the site is the kind of thing nobody notices until it is
     * screenshotted.
     *
     * @return EloquentBuilder<ForumUser>
     */
    private function eligibleMembers(CarbonInterface $cutoff): EloquentBuilder
    {
        return ForumUser::query()
            ->whereNull('verification_promotion_sent_at')   // never claimed
            ->whereNotNull('email_verified_at')             // actually verified
            ->where('email_verified_at', '<=', $cutoff)     // delay since the click has elapsed
            /*
             * CONSENT. Registering an account is not agreeing to be marketed
             * to, so the member has to have ticked the box on the registration
             * form. NULL — the default, and the state of every member who
             * registered before the box existed — is never eligible.
             *
             * This is the one condition here with no counterpart in the
             * subscriber sweep, and it does not need one: subscribing to a
             * newsletter IS the consent.
             */
            ->whereNotNull('marketing_consent_at')
            ->where('status', '!=', ForumUser::STATUS_BANNED)
            // Honour a global opt-out, the same way the subscriber sweep does —
            // any stream's opt-out excludes the address, and the send job
            // re-checks with Unsubscribe::hasAny.
            ->whereNotExists(function (Builder $query): void {
                $query->from('unsubscribes')
                    ->whereColumn('unsubscribes.email', 'forum_users.email')
                    ->whereColumn('unsubscribes.site_id', 'forum_users.site_id');
            });
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
