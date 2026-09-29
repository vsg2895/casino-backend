<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendWarmupCampaignJob;
use App\Models\WarmupEmail;
use App\Models\WarmupSend;
use App\Models\WarmupSendRecipient;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Puts the warmup section back to the state it had before its first run.
 *
 *   php artisan warmup:reset                 both of the below
 *   php artisan warmup:reset --last-sent     only the "Last sent" column
 *   php artisan warmup:reset --history       only the send history
 *   php artisan warmup:reset --force         skip the confirmation prompt
 *
 * ── What it clears ──────────────────────────────────────────────────────────
 *
 *  - `warmup_emails.last_sent_at` → NULL. That column is the ONLY thing the
 *    cooldown filter reads, so clearing it makes every address eligible again
 *    on the next run. It is what the admin shows as "Last sent".
 *
 *  - `warmup_send_recipients` and `warmup_sends` → deleted. That is the history
 *    table behind the admin's history screen, plus the run headers it belongs
 *    to.
 *
 * ── What it does NOT touch ──────────────────────────────────────────────────
 *
 * THE RECEIVER LIST ITSELF. Not one address is removed — this resets what the
 * platform REMEMBERS about them, never who is on the list. Deleting addresses
 * is a separate, deliberate act in the admin, and conflating the two here would
 * make "reset the last-sent column" a way to lose an imported seed list.
 *
 * Nothing about templates, schedules or the mail transport is touched either.
 *
 * ── Why it refuses while a run is in flight ─────────────────────────────────
 *
 * A warmup run holds a lock for its whole fan-out and its batches keep writing
 * history rows for minutes afterwards. Wiping the table underneath them would
 * leave the run half-recorded and `last_sent_at` stamped for addresses whose
 * history row had just been deleted — the two columns are meant to agree. So a
 * held lock aborts the command and says to press Stop first.
 */
class ResetWarmup extends Command
{
    protected $signature = 'warmup:reset
        {--last-sent : Only clear the "Last sent" column, keeping the history}
        {--history : Only clear the send history, keeping "Last sent"}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Clear warmup "Last sent" timestamps and/or the warmup send history';

    /** Rows removed per round-trip, so a long history never loads into memory. */
    private const int CHUNK = 1000;

    /**
     * How long the run lock is held while resetting.
     *
     * Long enough for a large history to be deleted in chunks, short enough that
     * a killed command cannot block warmup for the rest of the day.
     */
    private const int LOCK_SECONDS = 300;

    public function handle(): int
    {
        // Neither flag means both — the plain command is the common case, and
        // making the operator name both parts to get the obvious behaviour would
        // be a trap rather than a safeguard.
        $lastSent = (bool) $this->option('last-sent');
        $history = (bool) $this->option('history');

        if (! $lastSent && ! $history) {
            $lastSent = $history = true;
        }

        /*
         * Take the run lock for the whole reset.
         *
         * This is both the check and the guard. Failing to take it means a run
         * holds it, which is the one state this command must refuse — its
         * batches keep writing history for minutes and would be left
         * half-recorded. Holding it while we work also stops a run STARTING
         * midway through, which a read-only check could not.
         */
        $lock = Cache::lock(SendWarmupCampaignJob::runLockKey(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->error('A warmup run is in progress. Stop it in the admin (Warmup → Stop) and run this again.');

            return self::FAILURE;
        }

        try {
            return $this->reset($lastSent, $history);
        } finally {
            // Only ever releases the lock this command took: a run that starts
            // after we finish owns its own.
            $this->release($lock);
        }
    }

    /** @return int Exit code. */
    private function reset(bool $lastSent, bool $history): int
    {

        $stamped = $lastSent ? WarmupEmail::query()->whereNotNull('last_sent_at')->count() : 0;
        $attempts = $history ? WarmupSendRecipient::query()->count() : 0;
        $runs = $history ? WarmupSend::query()->count() : 0;

        if ($stamped === 0 && $attempts === 0 && $runs === 0) {
            $this->info('Nothing to reset — no "Last sent" timestamps and no history.');

            return self::SUCCESS;
        }

        if (! $this->confirmReset($lastSent, $history, $stamped, $attempts, $runs)) {
            return self::SUCCESS;
        }

        if ($lastSent) {
            $cleared = $this->clearLastSent();
            $this->info("Cleared \"Last sent\" on {$cleared} address(es).");
        }

        if ($history) {
            [$deletedAttempts, $deletedRuns] = $this->clearHistory();
            $this->info("Deleted {$deletedAttempts} history row(s) and {$deletedRuns} run(s).");
        }

        $this->newLine();
        $this->comment('The receiver list itself is untouched — no address was removed.');

        if ($lastSent) {
            $this->comment('Every address is eligible again, whatever cooldown the next run uses.');
        }

        return self::SUCCESS;
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

    /** NULLs the cooldown stamp on every address that carries one. */
    private function clearLastSent(): int
    {
        return WarmupEmail::query()
            ->whereNotNull('last_sent_at')
            ->update(['last_sent_at' => null, 'updated_at' => now()]);
    }

    /**
     * Delete the history, attempts first.
     *
     * `warmup_send_recipients.warmup_send_id` cascades on delete, so removing
     * the runs alone would be enough on MySQL — but only where the engine
     * enforces it, and SQLite does not by default. Deleting both explicitly
     * makes the command behave identically on every connection, and leaves no
     * orphan rows if a future migration ever relaxes the constraint.
     *
     * @return array{0: int, 1: int}  attempts deleted, runs deleted
     */
    private function clearHistory(): array
    {
        $attempts = $this->deleteInChunks((new WarmupSendRecipient())->getTable());
        $runs = $this->deleteInChunks((new WarmupSend())->getTable());

        return [$attempts, $runs];
    }

    /** Chunked so a history of any size neither fills memory nor holds one long transaction. */
    private function deleteInChunks(string $table): int
    {
        $deleted = 0;

        do {
            $removed = DB::table($table)->limit(self::CHUNK)->delete();
            $deleted += $removed;
        } while ($removed > 0);

        return $deleted;
    }

    private function confirmReset(bool $lastSent, bool $history, int $stamped, int $attempts, int $runs): bool
    {
        $parts = [];

        if ($lastSent) {
            $parts[] = "clear \"Last sent\" on {$stamped} address(es)";
        }

        if ($history) {
            $parts[] = "permanently delete {$attempts} history row(s) and {$runs} run(s)";
        }

        $this->warn('About to ' . implode(' and ', $parts) . '.');
        $this->line('The receiver list itself is NOT touched.');

        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Refusing to reset without confirmation. Pass --force when running non-interactively.');

            return false;
        }

        if (! $this->confirm('Proceed?', false)) {
            $this->info('Aborted; nothing was changed.');

            return false;
        }

        return true;
    }
}
