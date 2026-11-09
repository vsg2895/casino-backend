<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is left of a HARD-deleted news post: its URL and its feed id. Nothing else.
 *
 * News is deleted for good now (NewsRemovalService force-deletes), so the row
 * that used to hold the slug and the source id after a soft delete is gone.
 * This table keeps exactly the two facts SEO and the scraper still need:
 *
 *  - `slug` — the old `/news/<slug>` URL answers 410 Gone for NEWS_GONE_DAYS
 *    (NewsController::gone), and the slug is never given to a new post, or a
 *    live article would be answered 410 by the site's proxy;
 *  - `source_ref` — news:scrape does not take the same feed item back in.
 *
 * Unique per site on the slug, so recording a deletion twice is an upsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deleted_news_slugs')) {
            return;
        }

        Schema::create('deleted_news_slugs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 255);
            $table->string('source_ref', 191)->nullable();
            // Whether the post was ever public: only those answer 410.
            $table->boolean('was_public')->default(false);
            $table->timestamp('deleted_at');

            $table->unique(['site_id', 'slug'], 'deleted_news_slugs_site_slug_unique');
            $table->index(['site_id', 'source_ref'], 'deleted_news_slugs_site_source_index');
            $table->index(['site_id', 'deleted_at'], 'deleted_news_slugs_site_deleted_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_news_slugs');
    }
};
