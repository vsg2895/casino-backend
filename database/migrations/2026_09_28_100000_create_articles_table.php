<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editorial guides, per site.
 *
 * SITE-SCOPED, not global master data, and that is the whole point. Casinos,
 * categories and offers are shared across all six domains, which is why so much
 * effort goes into keeping their PAGES distinct. Articles are the one content
 * type where the text itself belongs to one domain — so nothing here is shared
 * and nothing needs de-duplicating.
 *
 * `published_at` is nullable and is the ONLY publication control. A draft is a
 * row with no date; scheduling is the same field with a future one. A separate
 * boolean would let "published = true, published_at = null" exist, which has no
 * meaning.
 *
 * SEO overrides mirror the other content tables (canonical_url, noindex), so an
 * article behaves like every other page in the admin rather than being a special
 * case an editor has to learn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('articles')) {
            Schema::create('articles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained()->cascadeOnDelete();

                $table->string('title', 255);
                $table->string('slug', 255);
                // Shown on the listing. Kept separate from the body so a card
                // never renders a truncated first paragraph mid-sentence.
                $table->string('excerpt', 500)->nullable();
                $table->longText('body')->nullable();
                $table->string('hero_image_path', 500)->nullable();

                // Null = draft. A future date = scheduled.
                $table->timestamp('published_at')->nullable();
                // Editorial ordering within the listing; ties break on date.
                $table->unsignedInteger('position')->default(0);

                $table->string('meta_title', 255)->nullable();
                $table->string('meta_description', 500)->nullable();
                $table->string('canonical_url', 500)->nullable();
                $table->boolean('noindex')->default(false);

                $table->timestamps();
                $table->softDeletes();

                // Slugs are unique PER SITE, not globally: two domains may each
                // legitimately have a "bonus-terms-explained".
                $table->unique(['site_id', 'slug'], 'articles_site_slug_unique');
                // The public listing: this site's published articles, newest
                // first. Ordered to match so the query needs no filesort.
                $table->index(['site_id', 'published_at'], 'articles_site_published_index');
            });
        }

        if (Schema::hasTable('sites') && ! Schema::hasColumn('sites', 'guides_enabled')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->boolean('guides_enabled')->default(false)->after('byline_enabled');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');

        if (Schema::hasTable('sites') && Schema::hasColumn('sites', 'guides_enabled')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->dropColumn('guides_enabled');
            });
        }
    }
};
