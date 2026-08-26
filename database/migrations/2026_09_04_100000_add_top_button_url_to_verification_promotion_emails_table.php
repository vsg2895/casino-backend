<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the post-verification promotion's TOP button its own destination.
 *
 * Companion to the fix that makes that button render at all: `top_button_text`
 * was editable in the admin, validated, stored and returned by the API — but the
 * template never emitted it, so a label typed there went nowhere. Now that the
 * button exists, it needs a link of its own rather than borrowing the banner's,
 * exactly as `cta_button_url` does for the lower button.
 *
 * FALLBACK IS DELIBERATE: the template renders
 * `top_button_url ?: hero_url ?: siteUrl`, so a row that never sets it points
 * where the banner points. Nothing needs backfilling.
 *
 * string(500) and a plain string rather than a validated URL, matching
 * `cta_button_url` and `hero_url`: affiliate destinations carry tracking macros
 * and {{site_url}} placeholders that Laravel's `url` rule rejects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->string('top_button_url', 500)->nullable()->after('top_button_text');
        });
    }

    public function down(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->dropColumn('top_button_url');
        });
    }
};
