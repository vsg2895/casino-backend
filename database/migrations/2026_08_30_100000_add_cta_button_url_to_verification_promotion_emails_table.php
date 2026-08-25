<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the "Claim your offer" button its own destination.
 *
 * Until now the CTA reused `hero_url`, the banner link — so the button, the
 * banner image and the header brand text all pointed at one URL and could not be
 * aimed separately. The CTA is the one link in this email that actually matters
 * commercially, and it is the one that most often needs its own tracked landing
 * page.
 *
 * FALLBACK IS DELIBERATE, and is why this is safe to deploy: the template renders
 * `cta_button_url ?: hero_url ?: siteUrl`. Every existing row has NULL here, so
 * the button keeps pointing exactly where it points today until an admin fills
 * the new field in. Nothing needs backfilling.
 *
 * string(500) matches `hero_url`, and it is a plain string rather than a
 * validated URL for the same reason `hero_url` is: affiliate destinations carry
 * tracking macros and placeholders ({{site_url}}) that Laravel's `url` rule
 * rejects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->string('cta_button_url', 500)
                ->nullable()
                ->after('cta_button_text');
        });
    }

    public function down(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->dropColumn('cta_button_url');
        });
    }
};
