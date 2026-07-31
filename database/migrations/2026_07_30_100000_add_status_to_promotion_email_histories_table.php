<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an outcome `status` (success | failed | skipped) to the promotion
 * history so EVERY processing attempt is recorded, not only deliveries.
 * Existing rows predate the column and were all successful sends → 'success'
 * via the column default.
 *
 * The unique key widens from (site_id, email, sent_date) to include `status`:
 * the table now holds one row per outcome per email/day (an address can fail
 * at 03:00 and succeed on the 03:30 retry), while the DB still guarantees at
 * most ONE `success` row per email/day — the send flow's dedup + "no duplicate
 * successful record" invariant. `sent_date` stays in the key because MySQL
 * requires every unique key on a partitioned table to contain the partition
 * column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('promotion_email_histories', function (Blueprint $table): void {
                $table->string('status', 10)->default('success')->after('sent_date');
            });
            Schema::table('promotion_email_histories', function (Blueprint $table): void {
                $table->dropUnique('promo_hist_unique');
                $table->unique(['site_id', 'email', 'sent_date', 'status'], 'promo_hist_unique');
            });

            return;
        }

        // One ALTER so the partitioned table is rebuilt a single time.
        DB::statement(<<<'SQL'
            ALTER TABLE promotion_email_histories
                ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'success' AFTER sent_date,
                DROP KEY promo_hist_unique,
                ADD UNIQUE KEY promo_hist_unique (site_id, email, sent_date, status)
            SQL);
    }

    public function down(): void
    {
        // The narrower key only fits success rows — drop attempt rows first.
        DB::table('promotion_email_histories')->where('status', '!=', 'success')->delete();

        if (DB::getDriverName() !== 'mysql') {
            Schema::table('promotion_email_histories', function (Blueprint $table): void {
                $table->dropUnique('promo_hist_unique');
                $table->unique(['site_id', 'email', 'sent_date'], 'promo_hist_unique');
            });
            Schema::table('promotion_email_histories', function (Blueprint $table): void {
                $table->dropColumn('status');
            });

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE promotion_email_histories
                DROP KEY promo_hist_unique,
                ADD UNIQUE KEY promo_hist_unique (site_id, email, sent_date),
                DROP COLUMN status
            SQL);
    }
};
