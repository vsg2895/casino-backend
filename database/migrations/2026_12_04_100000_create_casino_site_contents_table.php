<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One site's own SEO content for a shared casino.
 *
 * `casinos.description` / `meta_title` / `meta_description` / `noindex` are the
 * DEFAULT content: what the default site (winpalack) publishes and what every
 * other site falls back to. A row here overrides them for one site only, field
 * by field; NULL in any column means "use the default".
 *
 * Additive. No row is created for any site by this migration — overrides are
 * written by a person in the admin or by CasinoSeoContentSeeder — never copied
 * from the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('casino_site_contents')) {
            return;
        }

        Schema::create('casino_site_contents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('casino_id')->constrained('casinos')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->longText('description')->nullable();
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 500)->nullable();
            // NULL = inherit; true/false = this site's explicit Noindex/Index.
            $table->boolean('noindex')->nullable();
            $table->timestamps();

            $table->unique(['casino_id', 'site_id']);
            // Every public read looks rows up by site first.
            $table->index(['site_id', 'casino_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casino_site_contents');
    }
};
