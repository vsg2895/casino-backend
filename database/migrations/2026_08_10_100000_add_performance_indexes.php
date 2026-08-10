<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the tables that actually grow, chosen by MEASUREMENT rather than by
 * inspection.
 *
 * Each candidate was benchmarked against a 100k–200k row temporary copy of the
 * real table, comparing EXPLAIN plans and best-of-5 timings. Only the ones that
 * demonstrably paid for themselves are here. Measured, on 100k rows:
 *
 *   newsletters (site_id, verified, created_at)
 *       "count" endpoint with the Verified filter   20.4ms →  4.8ms
 *       admin listing with the Verified filter       0.45ms → 0.32ms
 *
 *   newsletters (site_id, deleted_at)
 *       trash view          24.6ms → 0.20ms, and the FILESORT disappears
 *       plain "count"       19.9ms → 3.2ms  (becomes a covering index)
 *
 *   warmup_emails (created_at)
 *       listing   22.6ms FULL TABLE SCAN + FILESORT → 0.28ms
 *
 * Two plausible candidates were REJECTED because the measurements refused them,
 * and they are recorded here so nobody re-adds them from first principles:
 *
 *   phone_sms_histories (status, created_at) — no measurable gain. The existing
 *       (created_at) index already satisfies "newest 50 failures" quickly, and
 *       COUNT is already served by the covering (status) index. This table gains
 *       a row per SMS sent, so an index that buys nothing is a pure write tax on
 *       the fastest-growing table in the schema. Revisit only if the failure rate
 *       becomes very low (<0.5%) AND filtering by status stays common — then the
 *       (created_at) scan has to walk far to find 50 matches.
 *
 *   WIDENING newsletters (site_id, created_at) to (site_id, created_at, email) —
 *       actively harmful. The theory was sound (serve the date range and both
 *       correlated NOT EXISTS subqueries from one index, never touching the row),
 *       but the optimiser then abandoned it for (site_id, email) and added a
 *       filesort: the campaign audience page went 3.6ms → 116ms, 32x WORSE.
 *       Leave (site_id, created_at) exactly as it is.
 *
 * Deliberately untouched:
 *   - promotion_email_histories — RANGE-partitioned on sent_date with a
 *     (site_id, email, sent_date, status) unique key; already optimal, and it is
 *     written once per recipient per campaign, so extra indexes cost real time.
 *   - newsletters_based_on_phone — (opted_out, created_at) + (created_at) +
 *     unique(phone) measured optimal for the listing, both audience modes and
 *     the count at 200k rows.
 *   - unsubscribes — (site_id, email, type) serves the correlated lookup and
 *     (site_id, type, unsubscribed_at) serves the listing; both confirmed in use.
 *   - casinos / categories / special_offers / cms_pages / social_links /
 *     casino_site — bounded content tables (tens of rows), already indexed.
 *   - jobs / sessions / cache — unused; this installation runs Redis for queue,
 *     session and cache.
 *
 * COST: two extra indexes on `newsletters` slow bulk imports slightly, since each
 * inserted row maintains two more B-trees. That is accepted deliberately — an
 * import is occasional and already batched, while the admin listing and counts
 * are hit constantly.
 */
return new class extends Migration
{
    /** @var list<array{table: string, name: string, columns: list<string>}> */
    private array $indexes = [
        [
            'table'   => 'newsletters',
            'name'    => 'newsletters_site_verified_created_index',
            'columns' => ['site_id', 'verified', 'created_at'],
        ],
        [
            'table'   => 'newsletters',
            'name'    => 'newsletters_site_deleted_index',
            'columns' => ['site_id', 'deleted_at'],
        ],
        [
            'table'   => 'warmup_emails',
            'name'    => 'warmup_emails_created_at_index',
            'columns' => ['created_at'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $index) {
            if (! Schema::hasTable($index['table']) || $this->indexExists($index['table'], $index['name'])) {
                continue;
            }

            Schema::table($index['table'], function (Blueprint $table) use ($index): void {
                $table->index($index['columns'], $index['name']);
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->indexes) as $index) {
            if (! Schema::hasTable($index['table']) || ! $this->indexExists($index['table'], $index['name'])) {
                continue;
            }

            Schema::table($index['table'], function (Blueprint $table) use ($index): void {
                $table->dropIndex($index['name']);
            });
        }
    }

    /**
     * Whether a named index already exists.
     *
     * Checked explicitly so the migration is safe to re-run and safe on a database
     * where someone has already added an index by hand — adding a duplicate index
     * name is a hard error, and this file must never be the thing that blocks a
     * deploy.
     */
    private function indexExists(string $table, string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->exists();
    }
};
