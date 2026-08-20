<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "This subscriber has already been claimed for the post-verification
 * promotion" — the once-ever guard.
 *
 * Why a column instead of promotion_email_histories: that table's unique key is
 * (site_id, email, sent_date, status), i.e. it enforces at most one send per
 * address PER DAY. That is exactly right for a recurring campaign and exactly
 * wrong here, where the rule is at most one send EVER. A row sent today would
 * not block a second send tomorrow.
 *
 * This column is claimed with a conditional UPDATE
 * (`... SET verification_promotion_sent_at = ? WHERE id = ? AND
 * verification_promotion_sent_at IS NULL`), which is atomic in InnoDB. Only the
 * worker whose UPDATE reports one affected row proceeds to send, so concurrent
 * workers, duplicate jobs, queue retries, repeated verification clicks and an
 * overlapping cron all collapse to a single delivery. History still records the
 * outcome; this column is the lock.
 *
 * The composite index matches the eligibility scan's WHERE clause exactly
 * (unsent AND verified), so the sweep stays an index range scan as the
 * newsletters table grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->timestamp('verification_promotion_sent_at')->nullable()->after('verified');
            $table->index(['verification_promotion_sent_at', 'verified'], 'newsletters_verify_promo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropIndex('newsletters_verify_promo_idx');
            $table->dropColumn('verification_promotion_sent_at');
        });
    }
};
