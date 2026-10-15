<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes every message log past its retention window.
 *
 *   php artisan histories:prune
 *   php artisan histories:prune --months=6 --dry-run
 *   php artisan histories:prune --only=sms,unione
 *
 * Scheduled daily at 03:00 (routes/console.php). Two months is the default, and
 * it is the retention the admin's Email History section is now sized for: the
 * question these rows answer — "did this person get this message, and what did
 * the provider say" — is asked within days of a send, never about last spring.
 * Keeping them past that is storing recipients' addresses for no purpose.
 *
 * ── What it prunes, and by what ─────────────────────────────────────────────
 *
 * One stream per tab of Email History, plus the webhook event log that feeds
 * the UniOne one:
 *
 *   promotion          promotion_email_histories     sent_date
 *   post-verification  verification_promotion_sends  sent_at
 *   verify             verify_email_sends            sent_at
 *   warmup             warmup_sends                  created_at   (cascades)
 *   sms                phone_sms_histories           created_at
 *   unione             unione_sends                  created_at   (cascades)
 *   unione-events      unione_webhook_events         created_at
 *
 * The two cascades are the point of pruning the PARENT rather than the rows the
 * screen shows: `warmup_send_recipients` and `unione_send_chunks` /
 * `unione_send_recipients` are `ON DELETE CASCADE`, so a run leaves with its
 * recipients and nothing is orphaned. Pruning the child tables directly would
 * strip the rows out from under runs the operator can still see.
 *
 * ── What it deliberately does NOT prune ─────────────────────────────────────
 *
 * `mailgun_receiver_sends` and `smtp_receiver_sends` look like history and are
 * not: they are the daily claim that stops a receiver being mailed twice, and a
 * credential may carry a cooldown measured in months. Deleting a row there
 * would let an address be mailed again, which is a send decision, not a tidy-up.
 *
 * Every other stream was checked for the same trap before being added here. The
 * promotion history's 24-hour dedup reads one day; the post-verification
 * "once per subscriber" guard lives on `newsletters`, not in its log; warmup and
 * UniOne cooldowns read `last_sent_at` on the address. None of them looks back
 * two months.
 *
 * ── Partitions ──────────────────────────────────────────────────────────────
 *
 * `promotion_email_histories` is RANGE-partitioned by month. A partition whose
 * whole range sits before the cutoff is DROPPED, which is instant and frees the
 * disk — a 700k-row DELETE does neither. Only then are the stragglers in the
 * boundary partition deleted row by row. Nothing without a numeric range is
 * touched, so `p_future` can never be dropped.
 */
class PruneMessageHistories extends Command
{
    protected $signature = 'histories:prune
                            {--months=2 : How many months of history to keep}
                            {--only= : Comma-separated stream keys to prune (default: all)}
                            {--dry-run : Report what would be deleted, delete nothing}';

    protected $description = 'Delete promotion, post-verification, warmup, SMS and UniOne history past the retention window';

    /** Rows deleted per statement. Small enough that locks are never held long. */
    private const int CHUNK = 1000;

    private const string PARTITIONED_TABLE = 'promotion_email_histories';

    /**
     * stream key => [table, date column, what a row is]
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const array STREAMS = [
        'promotion'         => ['promotion_email_histories', 'sent_date', 'promotion send'],
        'post-verification' => ['verification_promotion_sends', 'sent_at', 'after-verification send'],
        'verify'            => ['verify_email_sends', 'sent_at', 'verify-email send'],
        'warmup'            => ['warmup_sends', 'created_at', 'warmup run (with its recipients)'],
        'sms'               => ['phone_sms_histories', 'created_at', 'SMS attempt'],
        'unione'            => ['unione_sends', 'created_at', 'UniOne run (with its chunks and recipients)'],
        'unione-events'     => ['unione_webhook_events', 'created_at', 'UniOne delivery event'],
    ];

    public function handle(): int
    {
        $months = (int) $this->option('months');

        if ($months < 1) {
            $this->error('Retention must be at least 1 month.');

            return self::FAILURE;
        }

        $streams = $this->selectedStreams();

        if ($streams === null) {
            return self::FAILURE;
        }

        /*
         * Midnight, so a run at 03:00 and a re-run at 03:40 agree on the cutoff
         * and the second one has nothing left to do. UTC for the same reason the
         * rest of the messaging subsystem uses it: the servers' clock, not the
         * reader's.
         */
        $cutoff = Carbon::now('UTC')->subMonths($months)->startOfDay();
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '%s history older than %s (keeping %d month(s)).',
            $dryRun ? 'Would prune' : 'Pruning',
            $cutoff->toDateTimeString(),
            $months,
        ));

        $rows = [];
        $total = 0;

        foreach ($streams as $key) {
            [$table, $column, $unit] = self::STREAMS[$key];

            if (! Schema::hasTable($table)) {
                $rows[] = [$key, $table, '—', 'table missing'];

                continue;
            }

            $dropped = $table === self::PARTITIONED_TABLE
                ? $this->dropPartitionsBefore($cutoff, $dryRun)
                : [];

            $count = $this->pending($table, $column, $cutoff);

            if (! $dryRun && $count > 0) {
                $count = $this->deleteInChunks($table, $column, $cutoff);
            }

            $total += $count;
            $rows[] = [
                $key,
                $table,
                (string) $count,
                $dropped === [] ? $unit : $unit . ' · partitions: ' . implode(', ', $dropped),
            ];
        }

        $this->newLine();
        $this->table(['Stream', 'Table', $dryRun ? 'Would delete' : 'Deleted', 'Notes'], $rows);
        $this->info(($dryRun ? 'Would delete ' : 'Deleted ') . $total . ' row(s) in total.');

        if ($dryRun) {
            $this->comment('Dry run — nothing was deleted. Drop --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * The stream keys to work on, or null when the --only list names one that
     * does not exist (a typo must not silently prune everything).
     *
     * @return list<string>|null
     */
    private function selectedStreams(): ?array
    {
        $only = trim((string) $this->option('only'));

        if ($only === '') {
            return array_keys(self::STREAMS);
        }

        $keys = array_values(array_filter(array_map('trim', explode(',', $only))));
        $unknown = array_diff($keys, array_keys(self::STREAMS));

        if ($unknown !== []) {
            $this->error('Unknown stream(s): ' . implode(', ', $unknown));
            $this->line('Available: ' . implode(', ', array_keys(self::STREAMS)));

            return null;
        }

        return $keys;
    }

    private function pending(string $table, string $column, Carbon $cutoff): int
    {
        return DB::table($table)->where($column, '<', $cutoff)->count();
    }

    /** Chunked so the delete never holds a lock for longer than a thousand rows. */
    private function deleteInChunks(string $table, string $column, Carbon $cutoff): int
    {
        $deleted = 0;

        do {
            $batch = DB::table($table)->where($column, '<', $cutoff)->limit(self::CHUNK)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        return $deleted;
    }

    /**
     * Drop every monthly partition that lies entirely before the cutoff.
     *
     * A partition is declared `VALUES LESS THAN (TO_DAYS('YYYY-MM-01'))`, so its
     * description IS its exclusive upper bound in days. When that bound is at or
     * below the cutoff, every row it can hold is older than the cutoff and the
     * whole partition can go — instantly, and giving the disk back, which a
     * DELETE does not.
     *
     * Guards, because this is DDL against a live table:
     *  - only on MySQL (the test suite runs on SQLite, which has no partitions);
     *  - only partitions whose description is a plain integer, which excludes
     *    `p_future` (MAXVALUE) and anything hand-made;
     *  - strictly `<=` the cutoff, so the partition holding the cutoff's own
     *    month is always kept and swept row by row instead.
     *
     * @return list<string> the partitions dropped (or that would be)
     */
    private function dropPartitionsBefore(Carbon $cutoff, bool $dryRun): array
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return [];
        }

        $bound = (int) DB::selectOne('SELECT TO_DAYS(?) AS d', [$cutoff->toDateString()])->d;

        $partitions = DB::select(
            'SELECT PARTITION_NAME AS name, PARTITION_DESCRIPTION AS upper_bound '
            . 'FROM information_schema.PARTITIONS '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL '
            . 'ORDER BY PARTITION_ORDINAL_POSITION',
            [DB::getDatabaseName(), self::PARTITIONED_TABLE],
        );

        $dropped = [];

        foreach ($partitions as $partition) {
            $description = (string) $partition->upper_bound;

            if (! ctype_digit($description) || (int) $description > $bound) {
                continue;
            }

            $name = (string) $partition->name;

            if (! $dryRun) {
                DB::statement(sprintf(
                    'ALTER TABLE `%s` DROP PARTITION `%s`',
                    self::PARTITIONED_TABLE,
                    $name,
                ));
            }

            $dropped[] = $name;
        }

        return $dropped;
    }
}
