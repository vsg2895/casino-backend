<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the decorative star rating from the post-verification promotion.
 *
 * The "★★★★★" row read like a casino score with no data behind it, which
 * undermines trust rather than building it. The offer "ticket" (bonus amount +
 * Wagering / Min deposit / Offer ends) now carries the REAL numbers a subscriber
 * checks, so the empty stars are removed entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->dropColumn('rating_stars');
        });
    }

    public function down(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->string('rating_stars', 20)->nullable()->after('eyebrow_text');
        });
    }
};
