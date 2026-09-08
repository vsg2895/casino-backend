<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Countries a casino can be attached to, grouped by continent.
 *
 * Two tables rather than a `continent` string column on `countries`: the
 * continent is rendered as its own heading with its own ordering on the public
 * grid, and a free-text column would let "Australia & Oceania" and
 * "Australia and Oceania" both exist and split the group in half.
 *
 * `casino_country` follows `casino_category` exactly — a bare composite-primary
 * pivot with no payload. There is deliberately nothing per-site on it: a casino
 * accepts players from a country or it does not, and that fact does not change
 * depending on which of the network's domains is displaying it. Per-site
 * behaviour already has its home in `casino_site`.
 *
 * Every table is created only when absent, so a re-run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('continents')) {
            Schema::create('continents', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                // Display order of the headings on the public grid. Explicit,
                // because the intended order is neither alphabetical nor
                // creation order.
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();

                $table->index(['position', 'name']);
            });
        }

        if (! Schema::hasTable('countries')) {
            Schema::create('countries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('continent_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('slug')->unique();
                // ISO 3166-1 alpha-2 where one exists. NULLABLE and not unique:
                // the grid also carries entries that are not countries — a
                // Europe-wide card, an "Arab" card — and those have no code.
                $table->char('code', 2)->nullable()->index();
                // Uploaded through the same media flow casinos use. Null until
                // someone uploads a flag; `code` is enough to render one in the
                // meantime.
                $table->string('image_path')->nullable();
                $table->unsignedSmallInteger('position')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();

                // Serves the public grid's "this continent's countries, in
                // order" query without a filesort.
                $table->index(['continent_id', 'position', 'name']);
                $table->index('active');
            });
        }

        if (! Schema::hasTable('casino_country')) {
            Schema::create('casino_country', function (Blueprint $table): void {
                $table->foreignId('country_id')->constrained()->cascadeOnDelete();
                $table->foreignId('casino_id')->constrained()->cascadeOnDelete();
                $table->primary(['country_id', 'casino_id']);
                // The reverse direction — "which countries is this casino in?" —
                // is what the admin form reads, and the composite primary key
                // cannot serve it.
                $table->index('casino_id');
            });
        }
    }

    public function down(): void
    {
        // Pivot first: it holds the foreign keys into both tables.
        Schema::dropIfExists('casino_country');
        Schema::dropIfExists('countries');
        Schema::dropIfExists('continents');
    }
};
