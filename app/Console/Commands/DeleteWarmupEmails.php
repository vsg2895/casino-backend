<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendWarmupCampaignJob;
use App\Models\WarmupEmail;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Removes addresses from the warmup list.
 *
 *   php artisan warmup:delete-emails                         → the whole list
 *   php artisan warmup:delete-emails --email=seed@box.com     → one address (repeatable)
 *   php artisan warmup:delete-emails --never-sent             → only addresses never contacted
 *   php artisan warmup:delete-emails --since=2026-09-01       → only rows added since that day
 *   php artisan warmup:delete-emails --dry-run                → report, delete nothing
 *   php artisan warmup:delete-emails --force                  → no confirmation prompt
 *
 * Filters are optional and compose; omitting them all targets every address.
 * Sibling of {@see DeleteMailgunReceivers}, and deliberately simpler in two
 * ways, both of which come from what a warmup address IS:
 *
 *  - NO SOFT DELETE TO CHOOSE BETWEEN. `warmup_emails` does not soft delete, so
 *    there is no recoverable mode to offer and no trashed rows to sweep up.
 *    Every run of this command is permanent.
 *  - NOTHING TO CARRY OVER. A receiver is a person, so deleting one has to
 *    preserve their opt-out. A warmup address is a seed mailbox the operator
 *    owns; it never unsubscribed and there is no consent to protect.
 *
 * ── The run lock, which is the whole safety of this command ─────────────────
 *
 * A warmup run dispatches batches carrying ADDRESS IDS and then sends through
 * them for minutes. Deleting rows underneath it would make those batches mail
 * nothing, or worse, record history against ids that no longer resolve. So this
 * takes the same run lock {@see ResetWarmup} does: failing to take it means a
 * run holds it, which is the one state where deleting is unsafe, and holding it
 * while the delete runs also stops a run STARTING midway through — something a
 * read-only check could not do.
 *
 * ── What survives ───────────────────────────────────────────────────────────
 *
 * `warmup_send_recipients.warmup_email_id` is `ON DELETE SET NULL`, so the send
 * history outlives the address: the row keeps its `email`, its template, its
 * outcome and its timestamp. That log is the evidence of what the sending
 * mailbox actually did, and tidying a list must never erase it. Run counts in
 * `warmup_sends` are untouched for the same reason.
 *
 * Rows go in id-ordered chunks, so a 100k list neither loads into memory nor
 * holds one long transaction.
 */
class DeleteWarmupEmails extends Command
{
    protected $signature = 'warmup:delete-emails
        {--email=* : Only this address (repeatable). Matched after a lowercase+trim.}
        {--never-sent : Only addresses that have never been contacted}
        {--since= : Only addresses added on or after this date (Y-m-d)}
        {--dry-run : Report what would be deleted and change nothing}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete addresses from the warmup list (permanent), leaving the send history intact';

    /** Rows removed per round-trip. */
    private const int CHUNK = 1000;

    /** Addresses listed by --dry-run before the count. */
    private const int SAMPLE = 10;

    /**
     * How long the run lock is held while deleting.
     *
     * Long enough for a large list, short enough that a killed command cannot
     * block warmup for the rest of the day — the same reasoning, and the same
     * window, as warmup:reset.
     */
    private const int LOCK_SECONDS = 900;

    public function handle(): int
    {
        try {
            $since = $this->since();
        } catch (InvalidFormatException) {
            $this->error('Invalid --since value. Use the Y-m-d format, e.g. --since=2026-09-01.');

            return self::INVALID;
        }

        $emails = $this->emails();

        /*
         * Take the run lock BEFORE counting, so the number shown in the prompt
         * is the number deleted: a run starting between the count and the
         * delete would change the list underneath the operator.
         */
        $lock = Cache::lock(SendWarmupCampaignJob::runLockKey(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->error('A warmup run is in progress. Stop it in the admin (Warmup → Stop) and run this again.');

            return self::FAILURE;
        }

        try {
            return $this->deleteList($emails, $since);
        } finally {
            // Only ever releases the lock this command took: a run that starts
            // after we finish owns its own.
            $this->release($lock);
        }
    }

    /**
     * The delete itself, with the run lock already held.
     *
     * Named `deleteList`, not `run`: Command::run() is public, and a private
     * method of that name is a fatal error before a single line executes.
     *
     * @param  list<string>  $emails
     * @return int Exit code.
     */
    private function deleteList(array $emails, ?Carbon $since): int
    {
        $total = $this->query($emails, $since)->count();

        if ($emails !== [] && $total < count($emails)) {
            // A typo in an address must not read as "already gone". Report the
            // misses by name and let the run continue with the hits.
            $this->reportUnmatched($emails);
        }

        if ($total === 0) {
            $this->info('No matching address — nothing to delete.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->reportDryRun($emails, $since, $total);

            return self::SUCCESS;
        }

        if (! $this->confirmDeletion($emails, $since, $total)) {
            return self::SUCCESS;
        }

        $deleted = $this->deleteInChunks($emails, $since, $total);

        $this->newLine();
        $this->info(sprintf('Deleted %d warmup address(es).', $deleted));
        $this->comment('The send history is intact (address id nulled, email kept), so the mailbox\'s record is unchanged.');

        return self::SUCCESS;
    }

    // ── Options ──────────────────────────────────────────────────────────────

    /**
     * The --email values, lowercased and trimmed.
     *
     * The list is imported from spreadsheets, so an address typed with padding
     * or a capital would otherwise match nothing while looking perfectly good.
     *
     * @return list<string>
     */
    private function emails(): array
    {
        /** @var list<string> $raw */
        $raw = (array) $this->option('email');

        return collect($raw)
            ->map(static fn (string $email): string => Str::lower(trim($email)))
            ->filter(static fn (string $email): bool => $email !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** The --since option as a start-of-day instant, or null when omitted. */
    private function since(): ?Carbon
    {
        $raw = trim((string) $this->option('since'));

        if ($raw === '') {
            return null;
        }

        // '!' resets every unparsed field, so the result is exactly 00:00:00 of
        // that day. The round-trip check rejects input Carbon would otherwise
        // accept loosely (e.g. '2026-9-1' or '2026-13-01').
        $date = Carbon::createFromFormat('!Y-m-d', $raw);

        if ($date->format('Y-m-d') !== $raw) {
            throw new InvalidFormatException("Not a Y-m-d date: {$raw}");
        }

        return $date;
    }

    // ── Selection ────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $emails
     * @return Builder<WarmupEmail>
     */
    private function query(array $emails, ?Carbon $since): Builder
    {
        $query = WarmupEmail::query();

        if ($emails !== []) {
            $query->whereIn('email', $emails);
        }

        // "Imported by mistake and never used" — the safest thing to remove,
        // because nothing about the mailbox's reputation depends on it.
        if ((bool) $this->option('never-sent')) {
            $query->whereNull('last_sent_at');
        }

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        return $query;
    }

    // ── Reporting ────────────────────────────────────────────────────────────

    /** @param  list<string>  $emails */
    private function reportUnmatched(array $emails): void
    {
        $found = WarmupEmail::query()->whereIn('email', $emails)->pluck('email')->all();
        $missing = array_values(array_diff($emails, $found));

        if ($missing !== []) {
            $this->warn('Not on the warmup list: ' . implode(', ', $missing));
        }
    }

    /** @param  list<string>  $emails */
    private function reportDryRun(array $emails, ?Carbon $since, int $total): void
    {
        $this->info('Dry run — nothing was deleted.');
        $this->line($this->scope($emails, $since));
        $this->line(sprintf('  Would delete: %d address(es)', $total));

        $warmed = $this->query($emails, $since)->whereNotNull('last_sent_at')->count();
        $this->line(sprintf('  Of those, already warmed (have a "Last sent"): %d', $warmed));
        $this->line(sprintf('  Remaining on the list afterwards: %d', WarmupEmail::query()->count() - $total));

        $sample = $this->query($emails, $since)
            ->orderBy('id')
            ->limit(self::SAMPLE)
            ->pluck('email');

        if ($sample->isNotEmpty()) {
            $this->newLine();
            $this->line('  First ' . $sample->count() . ':');
            $sample->each(fn (string $email): mixed => $this->line('    ' . $email));

            if ($total > $sample->count()) {
                $this->line(sprintf('    … and %d more', $total - $sample->count()));
            }
        }
    }

    /**
     * Show the blast radius and get a yes, unless --force was passed.
     *
     * @param  list<string>  $emails
     */
    private function confirmDeletion(array $emails, ?Carbon $since, int $total): bool
    {
        $this->warn(sprintf('About to PERMANENTLY delete %d warmup address(es).', $total));
        $this->warn($this->scope($emails, $since));
        $this->comment('Warmup addresses do not soft delete: this cannot be undone from the admin.');

        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Refusing to delete without confirmation. Pass --force when running non-interactively.');

            return false;
        }

        if (! $this->confirm('Proceed?', false)) {
            $this->info('Aborted; nothing was deleted.');

            return false;
        }

        return true;
    }

    /**
     * One line naming the filters in force, so neither the prompt nor the dry
     * run can describe a different set than the one the query builds.
     *
     * @param  list<string>  $emails
     */
    private function scope(array $emails, ?Carbon $since): string
    {
        $parts = [];

        if ($emails !== []) {
            $parts[] = count($emails) === 1
                ? 'address ' . $emails[0]
                : count($emails) . ' listed addresses';
        }

        if ((bool) $this->option('never-sent')) {
            $parts[] = 'never contacted';
        }

        if ($since !== null) {
            $parts[] = 'added since ' . $since->toDateString();
        }

        return $parts === []
            ? 'Scope: the ENTIRE warmup list.'
            : 'Scope: ' . implode(', ', $parts) . '.';
    }

    // ── Deletion ─────────────────────────────────────────────────────────────

    /**
     * Delete in id-ordered chunks.
     *
     * Each pass re-runs the same query and the deleted rows are gone, so the
     * set shrinks until empty. Nothing larger than one chunk of ids is held in
     * memory.
     *
     * @param  list<string>  $emails
     */
    private function deleteInChunks(array $emails, ?Carbon $since, int $total): int
    {
        $progress = $this->output->createProgressBar($total);
        $progress->start();

        $deleted = 0;

        while (true) {
            $ids = $this->query($emails, $since)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $removed = WarmupEmail::query()->whereKey($ids)->delete();

            // A pass that matches rows but removes none would spin forever.
            if ($removed === 0) {
                $progress->finish();
                $this->newLine();
                $this->error('Delete affected no rows; stopping to avoid an endless loop.');

                return $deleted;
            }

            $deleted += $removed;
            $progress->advance($removed);
        }

        $progress->finish();

        return $deleted;
    }

    /** The lock frees itself after LOCK_SECONDS even if this fails. */
    private function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable $e) {
            $this->warn('Could not release the warmup run lock: ' . $e->getMessage());
        }
    }
}
