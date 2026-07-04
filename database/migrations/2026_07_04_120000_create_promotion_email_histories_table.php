<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Long-term "who got which promotion, when" history — built for big data, and
 * the single source of truth for the send flow (history + per-day idempotency).
 *
 * Every successful promotion delivery is kept forever for the admin history
 * view. The UNIQUE (site_id, email, sent_date) additionally makes delivery
 * **at most once per email per day**: batch jobs read it to skip anyone already
 * sent, and a bulk insertOrIgnore keeps a job retry / concurrent worker from
 * ever writing a duplicate. `sent_date` is part of the key because MySQL
 * requires every unique key on a partitioned table to contain the partition
 * column — which is exactly the column we dedup on, so it costs nothing.
 *
 * On MySQL it is RANGE-partitioned by month on `sent_date` so date-filtered
 * reads prune to a handful of partitions and each stays index-sized, while
 * inserts (bulk, one per batch) go straight to the current partition. Indexes
 * target the access patterns: per-day dedup + (site,email) lookups (the unique
 * key), date range per site, and prefix email search (`email LIKE 'term%'`). No
 * FK on site_id — partitioned InnoDB tables can't have them (and the app
 * already guarantees the reference).
 *
 * On other drivers (SQLite in tests) it degrades to a plain, equivalently
 * indexed table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::create('promotion_email_histories', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('site_id');
                $table->string('email');
                $table->date('sent_date');
                $table->dateTime('created_at')->nullable();

                // Per-day idempotency guard; also covers (site_id, email) lookups.
                $table->unique(['site_id', 'email', 'sent_date'], 'promo_hist_unique');
                $table->index(['site_id', 'sent_date'], 'promo_hist_site_date');
                $table->index('email', 'promo_hist_email');
            });

            return;
        }

        // Monthly partitions from the current month, 24 months ahead + a MAXVALUE
        // catch-all. A scheduled command keeps extending this window.
        $start = Carbon::now()->startOfMonth();
        $definitions = [];

        for ($i = 0; $i < 24; $i++) {
            $month = $start->copy()->addMonths($i);
            $next = $month->copy()->addMonth();
            $definitions[] = sprintf(
                "PARTITION p%s VALUES LESS THAN (TO_DAYS('%s'))",
                $month->format('Ym'),
                $next->format('Y-m-d'),
            );
        }
        $definitions[] = 'PARTITION p_future VALUES LESS THAN (MAXVALUE)';
        $partitions = implode(",\n            ", $definitions);

        DB::statement(<<<SQL
            CREATE TABLE promotion_email_histories (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                site_id BIGINT UNSIGNED NOT NULL,
                email VARCHAR(255) NOT NULL,
                sent_date DATE NOT NULL,
                created_at DATETIME NULL,
                PRIMARY KEY (id, sent_date),
                UNIQUE KEY promo_hist_unique (site_id, email, sent_date),
                KEY promo_hist_site_date (site_id, sent_date),
                KEY promo_hist_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            PARTITION BY RANGE (TO_DAYS(sent_date)) (
                {$partitions}
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_email_histories');
    }
};
