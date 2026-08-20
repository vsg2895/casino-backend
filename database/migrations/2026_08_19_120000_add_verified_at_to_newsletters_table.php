<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHEN the subscriber confirmed their address — the moment they clicked the
 * verify link.
 *
 * The `verified` boolean recorded only THAT they confirmed, never when, so the
 * post-verification promotion had to fall back to `created_at` (subscription
 * time). This column makes the intended rule expressible: the delay is measured
 * from the click.
 *
 * DELIBERATELY NOT BACKFILLED. Existing rows keep `verified_at = NULL`, and a
 * NULL is never eligible. Backfilling it — from created_at or from now() —
 * would make every already-verified subscriber eligible the moment the feature
 * is switched on, i.e. an unannounced bulk send to the whole list. Only people
 * who verify from here on receive the promotion.
 *
 * The index mirrors the eligibility query's WHERE clause (unclaimed + verified
 * within the window), replacing the created_at-era index that paired the claim
 * column with the `verified` boolean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->timestamp('verified_at')->nullable()->after('verified');
        });

        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropIndex('newsletters_verify_promo_idx');
            $table->index(['verification_promotion_sent_at', 'verified_at'], 'newsletters_verify_promo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropIndex('newsletters_verify_promo_idx');
        });

        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropColumn('verified_at');
            $table->index(['verification_promotion_sent_at', 'verified'], 'newsletters_verify_promo_idx');
        });
    }
};
