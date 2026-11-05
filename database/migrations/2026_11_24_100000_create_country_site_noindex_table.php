<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The countries a site keeps OUT of search engines: `noindex` on their
 * /countries/<slug> page and no sitemap entry — e.g. a country where online
 * gambling is prohibited. Additive only.
 *
 * Per site, not a column on `countries`: countries and the casinos they accept
 * are shared master data, so a flag there would change every site at once.
 * The page itself stays published and linked from the hub; only indexing
 * changes (admin → Sites → Edit → "Countries hidden from search").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('country_site_noindex', function (Blueprint $table): void {
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['site_id', 'country_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('country_site_noindex');
    }
};
