<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the per-site promotion email's top button its own destination, matching
 * the field the post-verification promotion already has.
 *
 * Until now the button's label was editable but its target was not: it borrowed
 * `cta_button_url`, then the offer link. That is fine while the button sits
 * under the banner as one of a pair, but it becomes the wrong default once the
 * button moves ABOVE the banner and is the first thing the reader can click.
 *
 * NULL DEFAULT, and null keeps today's behaviour exactly: the render falls back
 * to `cta_button_url` and then `hero_url`, so every existing row keeps pointing
 * where it already pointed. Nothing needs backfilling.
 *
 * Length matches `hero_url` and `cta_button_url` on this table — a destination
 * is a destination, and three different limits on three URL columns is how a
 * link silently truncates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_promotion_emails', function (Blueprint $table): void {
            $table->string('top_button_url', 500)->nullable()->after('top_button_text');
        });
    }

    public function down(): void
    {
        Schema::table('site_promotion_emails', function (Blueprint $table): void {
            $table->dropColumn('top_button_url');
        });
    }
};
