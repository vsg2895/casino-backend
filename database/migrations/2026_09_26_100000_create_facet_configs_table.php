<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which filters a site offers on its casino listing, and in what order.
 *
 * `docs/admin-first.md` is explicit that ordering and visibility are editorial:
 * a hardcoded facet list would make "stop offering the provider filter" a
 * deploy. The facets themselves are code — each one needs a query — but WHICH
 * are offered, their labels and their order are records.
 *
 * An empty table means "offer the built-in defaults", so this changes nothing
 * until a site configures it.
 *
 * Note what this table does NOT decide: whether a facet has any values. That is
 * derived from the data at request time, so a facet whose underlying field is
 * unpopulated is hidden even when it is configured and active. Offering a filter
 * where every choice returns nothing is worse than not offering it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('facet_configs')) {
            return;
        }

        Schema::create('facet_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // 'category' | 'country' | 'licence' | 'payment_method' | 'provider'
            $table->string('facet', 20);
            // Overrides the built-in wording, e.g. "Licence" -> "Regulated by".
            $table->string('label', 60)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);

            $table->timestamps();

            // One row per facet per site — two rows for the same facet is a
            // question with no correct answer.
            $table->unique(['site_id', 'facet'], 'facet_configs_site_facet_unique');
            $table->index(['site_id', 'active', 'position'], 'facet_configs_public_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facet_configs');
    }
};
