<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What `news:rewrite` last did with a scraped news post.
 *
 * Before this, "not rewritten" was all the admin could say: `body IS NULL`
 * cannot tell a draft the command has not reached yet from one it tried five
 * times and rejected every time, and the reason a rewrite was rejected or
 * failed only ever reached the console and the log.
 *
 * - rewrite_attempts     — every non-dry-run attempt, whatever its outcome
 * - rewrite_attempted_at — when the last one ran
 * - rewrite_result       — 'rewritten' | 'rejected' | 'failed' (last attempt)
 * - rewrite_error        — why the last attempt did not produce copy; null on success
 *
 * Backfill: posts that already carry a rewritten body are marked as one
 * successful attempt, dated by when their text was written. Drafts still
 * waiting stay at zero attempts — what happened to them before this column
 * existed was never recorded, and inventing a history would be worse than
 * admitting there is none.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('articles', 'rewrite_attempts')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->unsignedSmallInteger('rewrite_attempts')->default(0)->after('source_url');
            $table->timestamp('rewrite_attempted_at')->nullable()->after('rewrite_attempts');
            $table->string('rewrite_result', 16)->nullable()->after('rewrite_attempted_at');
            $table->string('rewrite_error', 500)->nullable()->after('rewrite_result');
        });

        $writtenAt = Schema::hasColumn('articles', 'content_updated_at')
            ? 'COALESCE(content_updated_at, updated_at)'
            : 'updated_at';

        DB::table('articles')
            ->whereNotNull('source_ref')
            ->whereNotNull('body')
            ->update([
                'rewrite_attempts'     => 1,
                'rewrite_attempted_at' => DB::raw($writtenAt),
                'rewrite_result'       => 'rewritten',
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('articles', 'rewrite_attempts')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn(['rewrite_attempts', 'rewrite_attempted_at', 'rewrite_result', 'rewrite_error']);
        });
    }
};
