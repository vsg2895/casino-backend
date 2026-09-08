<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site title and description patterns, one row per entity type.
 *
 * Today every default title and description is a literal in each site's
 * `lib/seo.ts` and `generateMetadata()`, so changing "Casino Review" to
 * "Casino Review 2026" is a deploy on six repositories. `docs/admin-first.md`
 * names this directly: defaults may be generated from a pattern, but the
 * pattern is stored in the admin and every record can override it.
 *
 * Patterns are resolved SERVER-SIDE. The front end receives finished strings and
 * never sees a token, which keeps the substitution rules in one place instead of
 * reimplemented per site.
 *
 * A missing row is the normal state and means "use the site's built-in
 * wording" — so this table changes nothing until someone writes a pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_templates')) {
            return;
        }

        Schema::create('seo_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // 'casino' | 'special_offer' | 'category' | 'page' | 'listing'
            $table->string('entity', 20);

            // Tokens: {{name}}, {{site_name}}, {{year}}, {{month}}.
            // Nullable independently — a site may want a title pattern and keep
            // the built-in description, or the reverse.
            $table->string('title_pattern', 255)->nullable();
            $table->string('description_pattern', 500)->nullable();

            $table->timestamps();

            // One pattern per entity per site. The unique index is what makes
            // "save the pattern for casinos on winpalack" an upsert rather than
            // a question about which of two rows wins.
            $table->unique(['site_id', 'entity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_templates');
    }
};
