<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EmailSchedule;
use App\Models\ScheduleSendHistory;
use App\Models\SendgridReceiver;
use App\Models\SendgridReceiverSend;
use App\Models\Site;
use App\Services\MailgunReceiverSendStateResetter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Puts the SendGrid side back to the state it had before its first run — the
 * counterpart of {@see ResetWarmup}, for schedules that send through SendGrid.
 *
 *   php artisan sendgrid:reset                      both of the below
 *   php artisan sendgrid:reset --last-sent          only the "Last sent" column
 *   php artisan sendgrid:reset --history            only the send history
 *   php artisan sendgrid:reset --site=goldenspinhq  narrow the HISTORY to one site
 *   php artisan sendgrid:reset --dry-run            report, delete nothing
 *   php artisan sendgrid:reset --force              skip the confirmation prompt
 *
 * ── What it clears ──────────────────────────────────────────────────────────
 *
 *  - `sendgrid_receivers.last_sent_at` → NULL and `sent_count` → 0. Those two
 *    are what the admin shows as "Last sent" / "Sent" on the SendGrid
 *    Receivers list. Delegated to {@see MailgunReceiverSendStateResetter},
 *    which also backs the "Reset send counters" button on the SendGrid Keys
 *    screen — so the CLI and the panel can never reset different things.
 *
 *  - `schedule_send_histories` rows whose provider is SendGrid, for EVERY
 *    stored key and for the .env key alike. That is the Email History →
 *    Schedules tab, and it is the table nothing could clear before this
 *    command existed: `histories:clear` covers six streams and not this one.
 *
 *  - `sendgrid_receiver_sends` rows — the per-key receiver history behind the
 *    SendGrid Keys screen.
 *
 * ── Why both halves matter, and why they are separable ──────────────────────
 *
 * They do different jobs. "Last sent" is what the COOLDOWN on a receiver list
 * reads; the schedule history is what the cooldown on a SCHEDULE reads
 * ({@see ScheduleSendHistory::scopeResting()}, the "Skip addresses contacted in
 * the last N days" field). Clearing one and not the other leaves an address
 * eligible by one rule and resting by the other, which is exactly the state
 * that makes a run look broken — so the plain command clears both, and the
 * flags exist for when only one is meant.
 *
 * ── What it does NOT touch ──────────────────────────────────────────────────
 *
 * THE RECEIVER LIST ITSELF. Not one address is removed, and neither are the
 * SendGrid keys, the schedules or any template. This resets what the platform
 * REMEMBERS about sending, never who it may send to. Unsubscribes are left
 * alone too: an opt-out is a person's decision, not send state.
 *
 * Mailgun and SMTP history are untouched — this command is about SendGrid.
 */
class ResetSendgridSendState extends Command
{
    protected $signature = 'sendgrid:reset
        {--last-sent : Only clear "Last sent" / "Sent" on the receivers, keeping the history}
        {--history : Only clear the send history, keeping "Last sent"}
        {--site= : Narrow the HISTORY to one site, by slug or id. "Last sent" is list-wide and ignores it}
        {--clear-errors : Also blank last_error on the receivers}
        {--dry-run : Report what would be cleared, change nothing}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Clear SendGrid "Last sent" counters and/or the SendGrid schedule send history';

    /** Rows removed per round-trip, so a long history never loads into memory. */
    private const int CHUNK = 1000;

    /**
     * The provider values that mean "this went through SendGrid".
     *
     * The .env key is included: its rows sit in the same Schedules tab under
     * the same heading, and an operator asking for the SendGrid history to be
     * cleared does not mean "all of it except the ones that used the key in
     * .env".
     */
    private const array PROVIDERS = [EmailSchedule::PROVIDER_SENDGRID, EmailSchedule::PROVIDER_SENDGRID_ENV];

    public function handle(): int
    {
        // Neither flag means both — the plain command is the common case, and
        // making the operator name both parts to get the obvious behaviour
        // would be a trap rather than a safeguard.
        $lastSent = (bool) $this->option('last-sent');
        $history  = (bool) $this->option('history');

        if (! $lastSent && ! $history) {
            $lastSent = $history = true;
        }

        $site = $this->resolveSite();

        if ($site === false) {
            return self::INVALID;
        }

        $clearErrors = (bool) $this->option('clear-errors');
        $resetter    = new MailgunReceiverSendStateResetter(SendgridReceiver::class);

        $stamped  = $lastSent ? $resetter->pendingCount($clearErrors) : 0;
        $rows     = $history ? $this->historyQuery($site)->count() : 0;
        $receipts = $history ? SendgridReceiverSend::query()->count() : 0;

        if ($stamped === 0 && $rows === 0 && $receipts === 0) {
            $this->info('Nothing to reset — no send counters and no SendGrid history.');

            return self::SUCCESS;
        }

        $this->report($lastSent, $history, $site, $stamped, $rows, $receipts);

        if ((bool) $this->option('dry-run')) {
            $this->newLine();
            $this->comment('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->confirmReset()) {
            return self::SUCCESS;
        }

        if ($lastSent) {
            $cleared = $resetter->reset($clearErrors);
            $this->info("Cleared \"Last sent\" and \"Sent\" on {$cleared} receiver(s).");
        }

        if ($history) {
            $deleted  = $this->deleteInChunks($this->historyQuery($site));
            $receipts = $this->deleteInChunks(SendgridReceiverSend::query());
            $this->info("Deleted {$deleted} schedule history row(s) and {$receipts} receiver send record(s).");
        }

        $this->newLine();
        $this->comment('The receiver list itself is untouched — no address was removed, and no opt-out was undone.');

        if ($lastSent || $history) {
            $this->comment('Addresses cleared here pass the cooldown again: the next run may mail them.');
        }

        return self::SUCCESS;
    }

    /**
     * The site named by --site, null for "every site", or false when the slug
     * or id matches nothing — which is an error rather than a silent
     * "everything", because the two differ by the whole network.
     */
    private function resolveSite(): Site|null|false
    {
        $value = trim((string) $this->option('site'));

        if ($value === '') {
            return null;
        }

        $site = Site::query()
            ->when(ctype_digit($value), fn (Builder $q) => $q->whereKey((int) $value))
            ->when(! ctype_digit($value), fn (Builder $q) => $q->where('slug', $value))
            ->first();

        if ($site === null) {
            $this->error("No site matches --site={$value}. Pass a slug (goldenspinhq) or an id.");

            return false;
        }

        return $site;
    }

    /** Every SendGrid row of the Schedules history tab, optionally one site's. */
    private function historyQuery(?Site $site): Builder
    {
        return ScheduleSendHistory::query()
            ->whereIn('provider', self::PROVIDERS)
            ->when($site !== null, fn (Builder $q) => $q->where('site_id', $site->id));
    }

    /** Show the blast radius before anything is touched. */
    private function report(bool $lastSent, bool $history, ?Site $site, int $stamped, int $rows, int $receipts): void
    {
        $scope = $site === null ? 'every site' : "site {$site->slug}";

        if ($lastSent) {
            $this->warn("About to clear \"Last sent\" and \"Sent\" on {$stamped} SendGrid receiver(s) — list-wide, every site.");
        }

        if ($history) {
            $this->warn("About to delete {$rows} SendGrid schedule history row(s) ({$scope}) and {$receipts} receiver send record(s).");
        }

        $this->warn('This removes cooldown protection: the next run may mail addresses it would otherwise have skipped.');
    }

    /** Ask, unless --force; refuse rather than guess when nobody can answer. */
    private function confirmReset(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Refusing to reset without confirmation. Pass --force when running non-interactively.');

            return false;
        }

        if (! $this->confirm('Proceed?', false)) {
            $this->info('Aborted; nothing was reset.');

            return false;
        }

        return true;
    }

    /**
     * Delete in chunks so a long history never loads into memory.
     *
     * `limit()` on a DELETE is MySQL's, which this project targets; the loop
     * stops as soon as a round-trip removes fewer rows than it asked for.
     */
    private function deleteInChunks(Builder $query): int
    {
        $total = 0;

        do {
            $deleted = (clone $query)->limit(self::CHUNK)->delete();
            $total += $deleted;
        } while ($deleted === self::CHUNK);

        return $total;
    }
}
