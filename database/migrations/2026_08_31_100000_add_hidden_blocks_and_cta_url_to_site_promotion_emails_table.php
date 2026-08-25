<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the per-site promotion email up to the same two capabilities the
 * post-verification promotion already has.
 *
 * `hidden_blocks` — every removable text and image becomes REVERSIBLE. Removing a
 * block used to mean clearing its field, which is destructive: the wording (or
 * the image URL) is gone, and "restore" could only put back a default rather than
 * what the operator actually had. Visibility and content are now independent, and
 * the list of hidden keys is one JSON column rather than a boolean per block —
 * same reasoning as 2026_08_29 on `verification_promotion_emails`.
 *
 * `cta_button_url` — the buttons reused `hero_url`, so the banner image, the top
 * button and the bottom button all pointed at one destination and could not be
 * aimed separately. The CTA is the link that matters commercially and most often
 * needs its own tracked landing page.
 *
 * BOTH ARE BACKWARD COMPATIBLE. `hidden_blocks` is empty, so every existing row
 * shows what it shows today; `cta_button_url` is null, so the buttons keep
 * falling back to `hero_url` exactly as before. Nothing needs backfilling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_promotion_emails', function (Blueprint $table): void {
            $table->json('hidden_blocks')->nullable()->after('unsubscribe_label');

            // string(500), matching hero_url. A plain string rather than a
            // validated URL for the same reason hero_url is: affiliate
            // destinations carry tracking macros and {{site_url}} placeholders
            // that Laravel's `url` rule rejects.
            $table->string('cta_button_url', 500)->nullable()->after('cta_button_text');
        });
    }

    public function down(): void
    {
        Schema::table('site_promotion_emails', function (Blueprint $table): void {
            $table->dropColumn(['hidden_blocks', 'cta_button_url']);
        });
    }
};
