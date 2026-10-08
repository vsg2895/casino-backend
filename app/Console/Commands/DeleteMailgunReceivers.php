<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MailgunReceiver;
use App\Models\MailgunSuppression;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * PERMANENTLY removes addresses from the Mailgun receiver list — a real
 * `DELETE FROM mailgun_receivers`, not the soft delete the admin's own Delete
 * button performs.
 *
 *   php artisan mailgun:delete-receivers                        → the whole list
 *   php artisan mailgun:delete-receivers --trashed              → only rows already soft-deleted
 *   php artisan mailgun:delete-receivers --email=a@b.com        → one address (repeatable)
 *   php artisan mailgun:delete-receivers --unsubscribed         → only those who opted out
 *   php artisan mailgun:delete-receivers --since=2026-09-01     → only rows added since that day
 *   php artisan mailgun:delete-receivers --dry-run              → report, write nothing
 *   php artisan mailgun:delete-receivers --force                → no confirmation prompt
 *
 * Filters are optional and compose; omitting them all targets every receiver,
 * soft-deleted rows included. There is no soft-delete mode — the admin already
 * has one, and a command whose whole purpose is the permanent delete must not be
 * able to quietly do the recoverable thing instead.
 *
 * WHY THE ADMIN SOFT-DELETES AND THIS DOES NOT
 * {@see \App\Http\Controllers\Api\Admin\MailgunReceiverController::destroy()}
 * keeps the row so it holds its place in the unique index on `email`: a later
 * re-import of the same address is then reported as a duplicate rather than
 * silently resurrecting somebody who was removed on purpose. Hard-deleting gives
 * that protection up, which is the single thing to understand before running
 * this: the address becomes importable again.
 *
 * WHICH OPT-OUT SURVIVES, AND HOW
 * `unsubscribed_at` lives on the receiver row, so deleting the row destroys the
 * record of that person's own decision — and with the unique-index guard gone, a
 * re-import would start mailing them again. So every matched receiver carrying
 * `unsubscribed_at` is first written to `mailgun_suppressions`, which is keyed by
 * email and read by {@see MailgunReceiver::scopeNotSuppressed()}: the opt-out
 * then outlives the row and survives any future import. Existing suppression
 * rows are never overwritten (a recorded bounce or complaint is the stronger
 * fact). `--forget-opt-outs` skips this, and is the only way to make the command
 * lose an opt-out.
 *
 * WHAT THE DATABASE DOES WITH THE CHILD ROWS (no cleanup needed here — these are
 * the FK rules in the migrations):
 *   - `receiver_daily_claims`  ON DELETE CASCADE  — today's claim goes with the
 *     row. Correct: the claim exists to stop a second send to an address that is
 *     about to not exist.
 *   - `mailgun_receiver_sends` and `smtp_receiver_sends`  ON DELETE SET NULL —
 *     the send history SURVIVES with a null receiver id. Both tables denormalise
 *     `email`, so the audit trail stays readable: it remains the record of what
 *     was actually delivered, which is never destroyed to tidy a list.
 *
 * Rows go in chunks, so a 100k list neither loads into memory nor holds one
 * enormous transaction.
 */
class DeleteMailgunReceivers extends Command
{
    protected $signature = 'mailgun:delete-receivers
        {--email=* : Only this address (repeatable). Matched after the same lowercase+trim the column stores.}
        {--trashed : Only receivers already soft-deleted — empty the recycle bin and nothing else}
        {--unsubscribed : Only receivers who opted out}
        {--since= : Only receivers created on or after this date (Y-m-d)}
        {--forget-opt-outs : Do NOT carry opted-out addresses over to the suppression list}
        {--dry-run : Report what would be deleted and change nothing}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Permanently delete Mailgun receivers (hard delete), carrying opt-outs over to the suppression list';

    /** Rows removed per round-trip. */
    private const int CHUNK = 1000;

    /** Addresses listed by --dry-run before the count. */
    private const int SAMPLE = 10;

    public function handle(): int
    {
        try {
            $since = $this->since();
        } catch (InvalidFormatException) {
            $this->error('Invalid --since value. Use the Y-m-d format, e.g. --since=2026-09-01.');

            return self::INVALID;
        }

        $emails = $this->emails();
        $total  = $this->query($emails, $since)->count();

        if ($emails !== [] && $total < count($emails)) {
            // A typo in an address must not read as "already gone". Report the
            // misses by name and let the run continue with the hits.
            $this->reportUnmatched($emails);
        }

        if ($total === 0) {
            $this->info('No matching receiver — nothing to delete.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->reportDryRun($emails, $since, $total);

            return self::SUCCESS;
        }

        if (! $this->confirmDeletion($emails, $since, $total)) {
            return self::SUCCESS;
        }

        [$deleted, $suppressed] = $this->deleteInChunks($emails, $since, $total);

        $this->newLine();
        $this->info(sprintf('Permanently deleted %d receiver(s).', $deleted));

        if ($suppressed > 0) {
            $this->info(sprintf(
                'Carried %d opt-out(s) over to the suppression list, so those addresses stay unmailable.',
                $suppressed,
            ));
        }

        $this->comment('Send history is intact (receiver id nulled). These addresses can be imported again.');

        return self::SUCCESS;
    }

    // ── Options ──────────────────────────────────────────────────────────────

    /**
     * The --email values, normalised the way the column stores them.
     *
     * Without the same lowercase+trim the model applies on write, `--email=A@X.com`
     * would match nothing while looking like a perfectly good address.
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
     * The set to delete. Always `withTrashed()`: a permanent delete that skipped
     * soft-deleted rows would leave exactly the rows the operator believes they
     * just removed.
     *
     * @param  list<string>  $emails
     * @return Builder<MailgunReceiver>
     */
    private function query(array $emails, ?Carbon $since): Builder
    {
        $query = MailgunReceiver::withTrashed();

        if ($emails !== []) {
            $query->whereIn('email', $emails);
        }

        if ((bool) $this->option('trashed')) {
            $query->whereNotNull('deleted_at');
        }

        if ((bool) $this->option('unsubscribed')) {
            $query->whereNotNull('unsubscribed_at');
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
        $found = MailgunReceiver::withTrashed()
            ->whereIn('email', $emails)
            ->pluck('email')
            ->all();

        $missing = array_values(array_diff($emails, $found));

        if ($missing !== []) {
            $this->warn('Not on the receiver list: ' . implode(', ', $missing));
        }
    }

    /**
     * What a real run would do, as numbers. Writes nothing.
     *
     * @param  list<string>  $emails
     */
    private function reportDryRun(array $emails, ?Carbon $since, int $total): void
    {
        $this->info('Dry run — nothing was deleted.');
        $this->line($this->scope($emails, $since));
        $this->line(sprintf('  Would permanently delete: %d receiver(s)', $total));

        $trashed = $this->query($emails, $since)->whereNotNull('deleted_at')->count();
        $this->line(sprintf('  Of those, already soft-deleted: %d', $trashed));

        $optOuts = $this->query($emails, $since)->whereNotNull('unsubscribed_at')->count();

        $this->line($this->keepsOptOuts()
            ? sprintf('  Opt-outs carried to the suppression list: %d', $optOuts)
            : sprintf('  Opt-outs that would be LOST (--forget-opt-outs): %d', $optOuts));

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
        $this->warn(sprintf(
            'About to PERMANENTLY delete %d receiver row(s) from the database, soft-deleted rows included.',
            $total,
        ));
        $this->warn($this->scope($emails, $since));
        $this->warn('These addresses lose their place in the unique index, so a future import can add them again.');

        if (! $this->keepsOptOuts()) {
            $this->warn('--forget-opt-outs: unsubscribed addresses will NOT be suppressed, so a re-import could mail them.');
        }

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
     * One line naming the filters in force, so neither the prompt nor the dry run
     * can describe a different set than the one the query builds.
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

        if ((bool) $this->option('trashed')) {
            $parts[] = 'already soft-deleted';
        }

        if ((bool) $this->option('unsubscribed')) {
            $parts[] = 'unsubscribed';
        }

        if ($since !== null) {
            $parts[] = 'created since ' . $since->toDateString();
        }

        return $parts === []
            ? 'Scope: the ENTIRE receiver list.'
            : 'Scope: ' . implode(', ', $parts) . '.';
    }

    // ── Deletion ─────────────────────────────────────────────────────────────

    /**
     * Delete in id-ordered chunks.
     *
     * Each pass re-runs the same query and the deleted rows are gone, so the set
     * shrinks until empty. Nothing larger than one chunk is held in memory.
     *
     * Opt-outs are suppressed BEFORE the delete in the same pass: a crash between
     * the two must leave an extra suppression, never an unmailable address that
     * became mailable again.
     *
     * @param  list<string>  $emails
     * @return array{0: int, 1: int}  deleted rows, suppressions written
     */
    private function deleteInChunks(array $emails, ?Carbon $since, int $total): array
    {
        $progress = $this->output->createProgressBar($total);
        $progress->start();

        $deleted    = 0;
        $suppressed = 0;

        while (true) {
            $rows = $this->query($emails, $since)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', 'email', 'unsubscribed_at']);

            if ($rows->isEmpty()) {
                break;
            }

            $suppressed += $this->preserveOptOuts($rows);

            $removed = MailgunReceiver::withTrashed()->whereKey($rows->pluck('id'))->forceDelete();

            // A pass that matches rows but removes none would spin forever.
            if ($removed === 0) {
                $progress->finish();
                $this->newLine();
                $this->error('Delete affected no rows; stopping to avoid an endless loop.');

                return [$deleted, $suppressed];
            }

            $deleted += $removed;
            $progress->advance($removed);
        }

        $progress->finish();

        return [$deleted, $suppressed];
    }

    /**
     * Carry the opt-outs in this chunk over to `mailgun_suppressions`.
     *
     * insertOrIgnore, not upsert: an address already suppressed keeps the reason
     * it has. A recorded bounce or complaint is a stronger fact about
     * deliverability than "the row was deleted", and overwriting it would hide
     * why the address went unmailable in the first place.
     *
     * Returns the number of suppression rows actually written.
     *
     * @param  Collection<int, MailgunReceiver>  $rows
     */
    private function preserveOptOuts(Collection $rows): int
    {
        if (! $this->keepsOptOuts()) {
            return 0;
        }

        $now = Carbon::now();

        $records = $rows
            ->filter(static fn (MailgunReceiver $receiver): bool => $receiver->unsubscribed_at !== null)
            ->map(static fn (MailgunReceiver $receiver): array => [
                'email'      => $receiver->email,
                'reason'     => MailgunSuppression::REASON_UNSUBSCRIBE,
                'detail'     => 'Receiver row permanently deleted on ' . $now->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        if ($records === []) {
            return 0;
        }

        return MailgunSuppression::query()->insertOrIgnore($records);
    }

    private function keepsOptOuts(): bool
    {
        return ! (bool) $this->option('forget-opt-outs');
    }
}
