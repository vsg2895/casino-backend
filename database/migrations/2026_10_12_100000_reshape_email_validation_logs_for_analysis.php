<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reshapes the validation log so it can be ANALYSED, not just read.
 *
 * Two changes carry the weight:
 *
 * 1. THE SIX CHECKS BECOME REAL COLUMNS. They were only inside the `checks`
 *    JSON, and the questions this table exists to answer are all filters and
 *    aggregates over them — "Risky, not disposable, but a role address" is the
 *    query that decides whether to start accepting Risky. JSON extraction
 *    cannot use an index, so on a growing table that query degrades to a full
 *    scan. `raw_checks` keeps the whole payload so nothing is lost if SendGrid
 *    adds fields.
 *
 * 2. `blocked` SPLITS INTO `hard_rejected` / `soft_rejected`, and a
 *    `reason_code` column names the rule that fired. A single "blocked" bucket
 *    cannot distinguish "this address cannot receive mail" from "this address
 *    probably works but failed policy" — and only the second is worth
 *    revisiting when tuning the thresholds.
 *
 * `score` widens to decimal(6,5): SendGrid returns five decimal places
 * (0.86474) and the old (5,4) silently rounded them, which would blunt the
 * histogram the threshold is chosen from.
 *
 * `was_skipped`/`skip_reason` are folded into `outcome` + `reason_code`; a skip
 * is a fail-open with a named reason, so keeping both was two ways to say one
 * thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_validation_logs')) {
            return;
        }

        Schema::table('email_validation_logs', function (Blueprint $table): void {
            $table->string('reason_code', 40)->nullable()->after('outcome');

            // Real columns, not JSON paths — see the note above.
            $table->boolean('has_valid_address_syntax')->nullable()->after('reason_code');
            $table->boolean('has_mx_or_a_record')->nullable()->after('has_valid_address_syntax');
            $table->boolean('is_suspected_disposable_address')->nullable()->after('has_mx_or_a_record');
            $table->boolean('is_suspected_role_address')->nullable()->after('is_suspected_disposable_address');
            $table->boolean('has_known_bounces')->nullable()->after('is_suspected_role_address');
            $table->boolean('has_suspected_bounces')->nullable()->after('has_known_bounces');
        });

        // Schema builder rather than raw ALTER ... CHANGE/MODIFY: that syntax is
        // MySQL-only and the test suite runs on SQLite, so raw DDL here would
        // mean the migration is never exercised by a single test.
        Schema::table('email_validation_logs', function (Blueprint $table): void {
            // Same column, a name that says it is the untouched payload rather
            // than the thing queries read.
            $table->renameColumn('checks', 'raw_checks');
        });

        Schema::table('email_validation_logs', function (Blueprint $table): void {
            // Five decimals, matching what SendGrid actually returns (0.86474).
            // The old (5,4) silently rounded, which would blunt the histogram
            // the score threshold is chosen from.
            $table->decimal('score', 6, 5)->nullable()->change();

            // Widened before anything writes the new values.
            $table->enum('outcome', ['allowed', 'hard_rejected', 'soft_rejected', 'failed_open'])->change();
        });

        // Carry any existing rows across rather than dropping them: a skip was
        // always a fail-open, and `blocked` was always the strict case.
        DB::table('email_validation_logs')->where('was_skipped', true)->update([
            'outcome' => 'failed_open',
        ]);
        DB::table('email_validation_logs')
            ->whereNotNull('skip_reason')
            ->whereNull('reason_code')
            ->update(['reason_code' => DB::raw('skip_reason')]);

        Schema::table('email_validation_logs', function (Blueprint $table): void {
            $table->dropColumn(['was_skipped', 'skip_reason']);

            // Analysis indexes. Each is (dimension, created_at) because every
            // screen filters by a dimension AND a date range, and a lone column
            // index would leave the sort to a filesort.
            $table->index(['verdict', 'created_at'], 'evl_verdict_created_idx');
            $table->index(['outcome', 'created_at'], 'evl_outcome_created_idx');
            $table->index('reason_code', 'evl_reason_idx');
            $table->index('score', 'evl_score_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_validation_logs')) {
            return;
        }

        Schema::table('email_validation_logs', function (Blueprint $table): void {
            $table->dropIndex('evl_verdict_created_idx');
            $table->dropIndex('evl_outcome_created_idx');
            $table->dropIndex('evl_reason_idx');
            $table->dropIndex('evl_score_idx');

            $table->boolean('was_skipped')->default(false);
            $table->string('skip_reason')->nullable();

            $table->dropColumn([
                'reason_code', 'has_valid_address_syntax', 'has_mx_or_a_record',
                'is_suspected_disposable_address', 'is_suspected_role_address',
                'has_known_bounces', 'has_suspected_bounces',
            ]);
        });

        Schema::table('email_validation_logs', function (Blueprint $table): void {
            $table->renameColumn('raw_checks', 'checks');
        });

        Schema::table('email_validation_logs', function (Blueprint $table): void {
            $table->decimal('score', 5, 4)->nullable()->change();
            $table->enum('outcome', ['allowed', 'blocked', 'failed_open'])->change();
        });
    }
};
