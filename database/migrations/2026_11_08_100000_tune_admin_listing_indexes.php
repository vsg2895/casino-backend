<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the admin listings that still sort the whole table to show one
 * page. Schema only — not one row of data is read, written or moved.
 *
 * Sibling of `tune_hot_path_indexes`, which covered the PUBLIC read paths and
 * the background jobs. This one covers the screens an operator sits in front
 * of, which is where "the admin panel is slow" is actually felt.
 *
 * ── The shape, and why an index fixes it ────────────────────────────────────
 *
 * Every listing below is `ORDER BY <timestamp> DESC [, id DESC]`, optionally
 * behind `deleted_at IS NULL`, and no index on these tables starts with that
 * timestamp. `EXPLAIN` on each one reports the same two lines:
 *
 *     -> Sort: <table>.created_at DESC, limit input to 25 row(s) per chunk
 *         -> Filter: (<table>.deleted_at is null)
 *             -> Table scan on <table>
 *
 * A sort cannot be limited before it has seen every candidate row, so the cost
 * is the whole table on every page view, however small the page. With the index
 * MySQL walks it backwards and stops after the page — and because `id` is in
 * the index, the tiebreak that keeps paging stable (an import writes hundreds
 * of rows with one `created_at`) is part of the same walk instead of forcing
 * the sort back.
 *
 * Six listings, each named with the screen that pays for it:
 *
 *  1. casinos — the Casinos table, the most-opened screen in the panel. The
 *     rows are also the widest in the schema (a LONGTEXT `description` plus the
 *     SEO columns), so sorting them is not merely a row count.
 *  2. special_offers — the Special Offers table, which is UNPAGINATED: it
 *     sorts and returns every offer, so this is its whole cost.
 *  3. newsletters — the subscriber list, the largest table an operator browses.
 *  4. unsubscribes — grows for as long as the platform sends mail.
 *  5. phone_sms_histories — the SMS tab of Email History.
 *  6. verification_promotion_sends — the after-verification tab.
 *
 * ── The drops ───────────────────────────────────────────────────────────────
 *
 * Two single-column indexes become exact prefixes of a wider index on the same
 * table, and MySQL will always prefer the wider one, so they are pure write
 * cost on two tables that take bulk inserts. Dropping them keeps the index
 * count on `phone_sms_histories` unchanged — this migration is write-cost
 * neutral there, not a tax on sending.
 *
 * Dropping an index removes no data and is instantly reversible; `down()`
 * rebuilds both.
 *
 * ── Deliberately NOT indexed ────────────────────────────────────────────────
 *
 * `cms_pages` (ORDER BY title), `email_schedules`, `sites`, `redirects` and
 * `bonus_categories` are all bounded by the number of sites or by editorial
 * work — tens to low hundreds of rows, for ever. An index there buys nothing
 * measurable and costs a write on every save. `promotion_email_histories` is
 * partitioned by `sent_date`, where a local per-partition index cannot serve a
 * global `ORDER BY sent_date DESC` anyway.
 *
 * ── Production ──────────────────────────────────────────────────────────────
 *
 * On MySQL every statement is issued with `ALGORITHM=INPLACE, LOCK=NONE`, so
 * the server builds or drops each index with reads AND writes still running on
 * the table. Stating it explicitly is the point: if a future MySQL could only
 * satisfy one of these by copying the table, the statement FAILS rather than
 * silently locking a live table for the duration. Other drivers (SQLite under
 * test) go through the schema builder, which has no such clauses.
 *
 * `php artisan migrate` is the whole deployment step. No data is touched, so
 * there is nothing to back-fill and no artisan command to run afterwards.
 */
return new class extends Migration
{
    /**
     * Indexes to create: table => [name => columns].
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array ADD = [
        'casinos' => [
            // Casino::latest()->paginate() — the Casinos screen.
            'casinos_listing_idx' => ['deleted_at', 'created_at', 'id'],
        ],
        'special_offers' => [
            // SpecialOffer::latest()->get() — unpaginated, so this is all of it.
            'special_offers_listing_idx' => ['deleted_at', 'created_at', 'id'],
        ],
        'newsletters' => [
            // The default (unfiltered) subscriber page. The site-filtered views
            // already ride newsletters_site_id_created_at_index.
            'newsletters_listing_idx' => ['deleted_at', 'created_at', 'id'],
        ],
        'unsubscribes' => [
            // The default page. Site+type views ride unsubscribes_site_type_time_index.
            'unsubscribes_listing_idx' => ['unsubscribed_at', 'id'],
        ],
        'phone_sms_histories' => [
            // Email History → SMS.
            'phone_sms_histories_listing_idx' => ['created_at', 'id'],
        ],
        'verification_promotion_sends' => [
            // Email History → After verification.
            'vps_listing_idx' => ['sent_at', 'id'],
        ],
    ];

    /**
     * Indexes to remove: table => [names].
     *
     * Both are exact prefixes of a wider index on the same table.
     *
     * @var array<string, list<string>>
     */
    private const array DROP = [
        // Prefix of phone_sms_histories_listing_idx, added above.
        'phone_sms_histories' => [
            'phone_sms_histories_created_at_index',
        ],
        // Prefix of the existing warmup_emails_recency_index(created_at, id,
        // last_sent_at). Unrelated to the additions above — same class of
        // redundancy, found in the same pass, on a table that takes imports.
        'warmup_emails' => [
            'warmup_emails_created_at_index',
        ],
    ];

    /**
     * What `down()` puts back, so a rollback restores the schema exactly.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array RESTORE = [
        'phone_sms_histories' => [
            'phone_sms_histories_created_at_index' => ['created_at'],
        ],
        'warmup_emails' => [
            'warmup_emails_created_at_index' => ['created_at'],
        ],
    ];

    public function up(): void
    {
        // Additions first: a DROP never leaves a table with less coverage than
        // it started with, not even between two statements.
        foreach (self::ADD as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $this->addIndex($table, $name, $columns);
            }
        }

        foreach (self::DROP as $table => $names) {
            foreach ($names as $name) {
                $this->dropIndex($table, $name);
            }
        }
    }

    public function down(): void
    {
        foreach (self::RESTORE as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $this->addIndex($table, $name, $columns);
            }
        }

        foreach (self::ADD as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                $this->dropIndex($table, $name);
            }
        }
    }

    /** @param  list<string>  $columns */
    private function addIndex(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }

        // Re-runnable: a half-finished migration can be run again without
        // tripping over the indexes it already created.
        if ($this->onMysql()) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD INDEX `%s` (%s), ALGORITHM=INPLACE, LOCK=NONE',
                $table,
                $name,
                collect($columns)->map(static fn (string $c): string => "`{$c}`")->implode(', '),
            ));

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $name)) {
            return;
        }

        if ($this->onMysql()) {
            DB::statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`, ALGORITHM=INPLACE, LOCK=NONE', $table, $name));

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }

    /**
     * Driver-agnostic "does this index exist".
     *
     * Laravel's schema introspection reports indexes per table on every
     * supported driver, which keeps this migration runnable on the SQLite the
     * test suite uses as well as on production MySQL.
     */
    private function indexExists(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (strcasecmp((string) ($index['name'] ?? ''), $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function onMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
