<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * News categories — the label on every post, and the topic pills on the feed.
 *
 * PER SITE, unlike bonus_categories. The two look alike and are scoped
 * differently on purpose: a bonus type ("No Deposit") is a property of the OFFER
 * and means the same thing on every domain, whereas each site's editorial
 * sections are its own — winpalack writing about "Licensing" says nothing about
 * what another domain in the network covers. Sharing them would force six sites
 * into one editorial taxonomy.
 *
 * NULLABLE on the article. A post with no category still publishes and still
 * appears in the feed; it just carries no badge and no topic pill. Requiring a
 * category would mean the first post cannot be written until someone has
 * invented a taxonomy, which is the wrong order.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('news_categories')) {
            Schema::create('news_categories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('slug');
                $table->unsignedSmallInteger('position')->default(0);
                // Off removes the badge, the pill and the filter — the posts
                // themselves stay published and keep their URLs.
                $table->boolean('active')->default(true);
                $table->timestamps();

                // Slugs are per site: two domains may both have "Licensing" and
                // each owns its own /news?category=licensing.
                $table->unique(['site_id', 'slug']);
                $table->index(['site_id', 'active', 'position']);
            });
        }

        if (! Schema::hasColumn('articles', 'news_category_id')) {
            Schema::table('articles', function (Blueprint $table): void {
                $table->foreignId('news_category_id')
                    ->nullable()
                    ->after('type')
                    // nullOnDelete: removing a category must not delete the posts
                    // filed under it. They fall back to uncategorised.
                    ->constrained('news_categories')
                    ->nullOnDelete();

                $table->index(['site_id', 'type', 'news_category_id'], 'articles_site_type_cat_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('articles', 'news_category_id')) {
            Schema::table('articles', function (Blueprint $table): void {
                $table->dropIndex('articles_site_type_cat_idx');
                $table->dropConstrainedForeignId('news_category_id');
            });
        }

        Schema::dropIfExists('news_categories');
    }
};
