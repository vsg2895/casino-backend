<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colour of the verify email's footer text.
 *
 * ONE column for all three footer lines — the footer note, the address/contact
 * line and the copyright. They were three separate hardcoded `#9ca3af` values
 * that always matched; splitting them into three settings would invite them to
 * stop matching, which is the only way this block can look broken.
 *
 * The LINKS in that footer keep using `accent_color` and are deliberately not
 * covered here: an opt-out link that is the same grey as the text around it
 * stops reading as a link, and the unsubscribe link has to stay findable.
 *
 * NULLABLE, and null renders the same `#9ca3af` as before, so every existing
 * site's email is unchanged. Nothing needs backfilling.
 *
 * `string(9)` matches the other colour columns on these templates: long enough
 * for #rrggbbaa, short enough that nothing else fits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_verify_emails', function (Blueprint $table): void {
            $table->string('footer_text_color', 9)->nullable()->after('accent_color');
        });
    }

    public function down(): void
    {
        Schema::table('site_verify_emails', function (Blueprint $table): void {
            $table->dropColumn('footer_text_color');
        });
    }
};
