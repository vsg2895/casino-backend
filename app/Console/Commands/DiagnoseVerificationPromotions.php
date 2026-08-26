<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\PromotionMailerException;
use App\Models\Newsletter;
use App\Models\PromotionEmailHistory;
use App\Models\Unsubscribe;
use App\Models\VerificationPromotionEmail;
use App\Services\Mail\PromotionMailerFactory;
use App\Support\ClockFacts;
use App\Support\Mail\MailCredential;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Explains why the post-verification promotion is (or is not) going out.
 *
 * "The email did not arrive" has a dozen possible causes spread across config,
 * the scheduler, the queue and per-subscriber state, and the failure is silent
 * by design at every step — a disabled feature, an empty candidate set and a
 * stopped worker all look identical from the outside. This walks the whole
 * chain in order and reports where it stops.
 *
 * Read-only: it resolves the mail transport but never sends, and never writes.
 *
 *   php artisan promotions:diagnose-verification
 *   php artisan promotions:diagnose-verification someone@example.com
 */
class DiagnoseVerificationPromotions extends Command
{
    protected $signature = 'promotions:diagnose-verification
                            {email? : Explain this one subscriber in detail}';

    protected $description = 'Diagnose why the post-verification promotion is or is not sending';

    public function handle(PromotionMailerFactory $mailers): int
    {
        $config = VerificationPromotionEmail::current();
        $delay = max(0, (int) $config->delay_minutes);
        $cutoff = Carbon::now()->subMinutes($delay);

        $this->line('');
        $this->info('── 0. Clocks ──');
        // FIRST, because a timing complaint is almost always read off two
        // screens in two timezones. The admin renders timestamps in the
        // BROWSER's zone; everything below — and every line in laravel.log — is
        // in app.timezone. A constant offset between them is presentation, not
        // a bug. A drift between PHP and the database is a real one.
        $clock = ClockFacts::forLog();
        $drift = $clock['drift_seconds'];
        $this->row('App timezone', $clock['timezone'], true);
        $this->row('PHP now (' . $clock['timezone'] . ')', $clock['now'], true);
        $this->row('Database now (UTC)', $clock['db_now_utc'], $clock['db_now_utc'] !== 'unavailable');
        // Informational, never a failure: nothing in this feature reads a time
        // from the database, so its session zone cannot affect eligibility.
        $this->row('Database session timezone', $clock['db_timezone'] . '  (informational)', null);
        $this->row(
            'Clock skew, app vs database',
            $drift === null
                ? 'unknown (database clock unreadable)'
                : "{$drift}s" . ($drift > ClockFacts::DRIFT_TOLERANCE_SECONDS ? '  <- the two machines disagree' : ''),
            $drift !== null && $drift <= ClockFacts::DRIFT_TOLERANCE_SECONDS,
        );
        $this->line('    <fg=gray>The admin shows times in YOUR BROWSER\'s timezone. A fixed offset from the values</>');
        $this->line('    <fg=gray>above is display only: verified_at is stored and compared in ' . $clock['timezone'] . ' throughout.</>');

        $this->line('');
        $this->info('── 1. Feature configuration ──');
        $this->row('Enabled', $config->active ? 'yes' : 'NO  <- nothing will ever send', $config->active);
        $this->row('Delay', "{$delay} minute(s) after verified_at", true);
        $this->row('Eligible if verified_at <=', $cutoff->toDateTimeString(), true);
        $this->row('Provider', (string) $config->provider, true);
        $this->row('Credential id', $config->credentialId() === null ? 'none (env-configured)' : (string) $config->credentialId(), true);

        $this->line('');
        $this->info('── 2. Mail transport & credential ──');
        // Same descriptor the send logs, so what you check here is exactly what
        // you will see in the log afterwards.
        $credential = MailCredential::describe($config->provider, $config->credentialId());
        $this->row('Credential source', $credential['source'], true);
        $this->row('Key prefix', $credential['key_prefix'], $credential['key_prefix'] !== '(empty)');
        $this->row('Key fingerprint', $credential['key_fingerprint'], $credential['key_fingerprint'] !== '(none)');
        $this->line('    <fg=gray>compare with: printf %s "$SENDGRID_API_KEY" | shasum -a 256 | cut -c1-12</>');

        try {
            $resolved = $mailers->resolve($config->provider, $config->credentialId());
            $this->row('Resolves', get_class($resolved->mailer->getSymfonyTransport()), true);
            // The sender is this section's own from_email — NOT the factory's
            // default ($resolved->fromAddress, the .env SMTP mailbox), which is
            // what the send would use only if it applied an override. It does
            // not, so reporting that value here would name the wrong address.
            $this->row('From address (section)', (string) $config->from_email, trim((string) $config->from_email) !== '');
            $this->line('    <fg=gray>must be a sender authenticated in SendGrid, or delivery is rejected</>');
        } catch (PromotionMailerException $e) {
            $this->row('Resolves', 'NO  <- '.$e->getMessage(), false);
        } catch (Throwable $e) {
            $this->row('Resolves', 'ERROR  <- '.$e->getMessage(), false);
        }

        $this->line('');
        $this->info('── 3. Queue ──');
        // The sweep only ENQUEUES. If nothing drains the queue the mail never
        // goes out, and the sweep still reports success.
        try {
            $pending = Queue::connection()->size('low');
            $this->row('Jobs waiting on the "low" queue', (string) $pending,
                $pending === 0 ? true : null);
            if ($pending > 0) {
                $this->warn('   A backlog here means the worker is not draining the queue.');
                $this->warn('   Check: php artisan queue:work --queue=high,low  (supervisor)');
            }
        } catch (Throwable $e) {
            $this->row('Queue readable', 'NO  <- '.$e->getMessage(), false);
        }

        $this->line('');
        $this->info('── 3b. Scheduler overlap mutex ──');
        // The silent killer. `withoutOverlapping()` holds a cache mutex that is
        // released in a shutdown handler; a run that is KILLED rather than
        // finished leaves it behind, and every later tick then exits before
        // handle() — logging nothing at all. The other sweep has its own mutex
        // and keeps running, which is exactly what makes this look like "cron
        // works but this one command does not".
        $this->reportMutex('promotions:dispatch-verification');
        $this->reportMutex('promotions:dispatch-due');

        $this->line('');
        $this->info('── 4. Subscriber funnel (all sites) ──');
        // Each step is the previous one plus one more condition, so the row where
        // the number collapses to zero is the reason nobody is eligible.
        $base = Newsletter::query();
        $steps = [
            'Subscribers total'                  => (clone $base),
            '… verified = true'                  => (clone $base)->where('verified', true),
            '… with a verified_at timestamp'     => (clone $base)->where('verified', true)->whereNotNull('verified_at'),
            '… whose delay has elapsed'          => (clone $base)->where('verified', true)->whereNotNull('verified_at')->where('verified_at', '<=', $cutoff),
            '… not already sent/claimed'         => (clone $base)->where('verified', true)->whereNotNull('verified_at')->where('verified_at', '<=', $cutoff)->whereNull('verification_promotion_sent_at'),
            '… not unsubscribed from promos'     => $this->eligibleQuery($cutoff),
        ];
        foreach ($steps as $label => $query) {
            $this->row($label, (string) $query->count(), null);
        }

        $verifiedNoStamp = Newsletter::where('verified', true)->whereNull('verified_at')->count();
        if ($verifiedNoStamp > 0) {
            $this->line('');
            $this->warn("   {$verifiedNoStamp} subscriber(s) are verified but have NO verified_at.");
            $this->warn('   Those verified before the column existed and are deliberately never sent to.');
            $this->warn('   Only people who click the verify link from now on become eligible.');
        }

        $this->line('');
        $this->info('── 5. Per-site breakdown (the feature is global; this is only informational) ──');
        foreach ($this->eligibleQuery($cutoff)->selectRaw('site_id, count(*) as c')->groupBy('site_id')->pluck('c', 'site_id') as $siteId => $count) {
            $this->row("site_id {$siteId}", (string) $count, null);
        }

        if ($email = $this->argument('email')) {
            $this->explain((string) $email, $cutoff, $delay);
        }

        $this->line('');

        return self::SUCCESS;
    }

    /** The exact query the sweep runs. */
    private function eligibleQuery(Carbon $cutoff)
    {
        return Newsletter::query()
            ->whereNull('verification_promotion_sent_at')
            ->whereNotNull('verified_at')
            ->where('verified', true)
            ->where('verified_at', '<=', $cutoff)
            ->whereNotExists(function (Builder $query): void {
                $query->from('unsubscribes')
                    ->whereColumn('unsubscribes.email', 'newsletters.email')
                    ->whereColumn('unsubscribes.site_id', 'newsletters.site_id');
            });
    }

    /** Walk one subscriber through every condition the job checks. */
    private function explain(string $email, Carbon $cutoff, int $delay): void
    {
        $this->line('');
        $this->info("── 6. Subscriber: {$email} ──");

        $rows = Newsletter::withTrashed()->where('email', $email)->get();

        if ($rows->isEmpty()) {
            $this->row('Found', 'NO  <- no subscriber with that address', false);

            return;
        }

        foreach ($rows as $n) {
            $this->line("   site_id={$n->site_id}  id={$n->id}");
            $this->row('  Not soft-deleted', $n->trashed() ? 'NO  <- row is in the trash' : 'yes', ! $n->trashed());
            $this->row('  Site still exists', $n->site !== null ? 'yes' : 'NO  <- site deleted, job skips', $n->site !== null);
            $this->row('  verified', $n->verified ? 'yes' : 'NO  <- never clicked the link', (bool) $n->verified);
            $this->row('  verified_at', $n->verified_at?->toDateTimeString() ?? 'NULL  <- never eligible', $n->verified_at !== null);

            if ($n->verified_at !== null) {
                $eligibleAt = Carbon::parse($n->verified_at)->addMinutes($delay);
                $due = ! $eligibleAt->isFuture();
                $this->row('  Eligible at', $eligibleAt->toDateTimeString().($due ? '  (due)' : '  <- still in the future'), $due);
            }

            $claimed = $n->verification_promotion_sent_at;
            $this->row('  Already sent/claimed', $claimed === null ? 'no' : $claimed->toDateTimeString().'  <- will not send again', $claimed === null);

            $unsub = Unsubscribe::hasAny($n->site_id, $n->email);
            $this->row('  Opted out (any template)', $unsub ? 'YES  <- excluded' : 'no', ! $unsub);

            $history = PromotionEmailHistory::where('email', $n->email)
                ->where('site_id', $n->site_id)->orderByDesc('id')->first();
            $this->row('  Last promotion history', $history === null ? 'none' : "{$history->status} on {$history->sent_date}".($history->error ? " ({$history->error})" : ''), null);
        }
    }

    /**
     * Report whether a scheduled command is registered, and whether its
     * `withoutOverlapping` mutex is currently held.
     *
     * The mutex name is derived from the event's own command string, which is
     * built from the running PHP binary's path — so it is asked of the Schedule
     * rather than reconstructed here, where it would be wrong on any host whose
     * paths differ from this one.
     */
    private function reportMutex(string $signature): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            static fn (Event $event): bool => str_contains((string) $event->command, $signature),
        );

        if (! $event instanceof Event) {
            $this->row($signature, 'NOT SCHEDULED  <- absent from routes/console.php', false);

            return;
        }

        if (! $event->withoutOverlapping) {
            // The post-verification sweep is deliberately here: it cannot be
            // muted by a stranded lock because it never takes one.
            $this->row($signature, 'no mutex (cannot be blocked)', true);

            return;
        }

        try {
            $held = $event->mutex->exists($event);
        } catch (Throwable $e) {
            $this->row($signature . ' mutex', 'unreadable  <- ' . $e->getMessage(), null);

            return;
        }

        $this->row(
            $signature . ' mutex',
            $held
                // Ambiguous for exactly as long as a run takes, which is under a
                // second — so on a second consecutive HELD it is stale.
                ? 'HELD  <- stale, or a run is in flight; if it persists: php artisan schedule:clear-cache'
                : 'free',
            ! $held,
        );
    }

    private function row(string $label, string $value, ?bool $ok): void
    {
        $mark = $ok === null ? ' ' : ($ok ? '<fg=green>✓</>' : '<fg=red>✗</>');
        $this->line(sprintf('  %s %-38s %s', $mark, $label, $value));
    }
}
