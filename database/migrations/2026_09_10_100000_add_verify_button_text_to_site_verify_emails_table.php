<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The verify email's button label, until now hardcoded as "Verify My Email".
 *
 * NULLABLE, and null renders that same literal — so every existing site's email
 * is byte-identical after this migration. Nothing needs backfilling.
 *
 * DELIBERATELY NOT AN OPTIONAL BLOCK, unlike almost everything else in these
 * templates. This button is the entire purpose of the email: an admin who
 * removed it would ship a double opt-in message with no visible way to opt in,
 * and the failure would only surface as subscribers silently never confirming.
 * Clearing the field therefore restores the default label rather than dropping
 * the button — see SiteVerifyEmail::render().
 *
 * 80 chars matches `unsubscribe_label`, the other structural label on this
 * table; a button caption longer than that wraps inside the pill anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_verify_emails', function (Blueprint $table): void {
            $table->string('verify_button_text', 80)->nullable()->after('unsubscribe_label');
        });
    }

    public function down(): void
    {
        Schema::table('site_verify_emails', function (Blueprint $table): void {
            $table->dropColumn('verify_button_text');
        });
    }
};
