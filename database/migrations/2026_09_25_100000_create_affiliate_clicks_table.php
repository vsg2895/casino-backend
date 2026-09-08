<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily click counts on outbound affiliate links.
 *
 * A DAILY AGGREGATE, not a row per click. Per-click rows are a table that grows
 * without bound and answers no question the operator actually asked — "which
 * casinos get clicked" is a daily count. One row per (site, casino, offer, day),
 * incremented with an upsert.
 *
 * `special_offer_id` is NOT NULL with 0 meaning "the casino's own link rather
 * than a specific offer", and deliberately carries NO foreign key. Both choices
 * are forced by the unique index: MySQL treats NULLs in a unique index as
 * distinct, so a nullable column would let the same casino-level click insert
 * an unlimited number of duplicate rows for one day — the exact thing the index
 * exists to prevent.
 *
 * Counts survive the deletion of the offer they refer to, which is intended:
 * "this offer earned 400 clicks last month" stays true after the offer is
 * retired.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('affiliate_clicks')) {
            return;
        }

        Schema::create('affiliate_clicks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('casino_id')->constrained()->cascadeOnDelete();

            // 0 = the casino's own affiliate link. See the class docblock for
            // why this is not a nullable foreign key.
            $table->unsignedBigInteger('special_offer_id')->default(0);

            $table->date('clicked_on');
            $table->unsignedBigInteger('count')->default(0);

            $table->timestamps();

            // THE guard, and what makes the upsert an increment rather than an
            // insert. Ordered so the admin's "clicks for this casino, recently"
            // read is served by the same index.
            $table->unique(
                ['site_id', 'casino_id', 'special_offer_id', 'clicked_on'],
                'affiliate_clicks_daily_unique',
            );
            $table->index(['casino_id', 'clicked_on'], 'affiliate_clicks_casino_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_clicks');
    }
};
