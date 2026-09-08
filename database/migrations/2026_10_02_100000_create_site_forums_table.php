<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site settings for the player forum page.
 *
 * ITS OWN TABLE rather than more columns on `sites`. The forum page is editorial
 * content — a heading, an intro, an empty state, two SEO strings — and `sites`
 * is already carrying the credentials, the feature flags and the revalidation
 * health for every domain. A sibling of `site_promotion_emails`: one row per
 * site, created with defaults on first access.
 *
 * `enabled` is SEPARATE from `sites.reviews_enabled` and both must be true for
 * the page to exist. They answer different questions: `reviews_enabled` is
 * "does this site collect and show reviews at all", `enabled` is "does it also
 * publish the combined forum page". A site can want the per-casino review
 * section without a forum; the reverse is meaningless, which is why the feed
 * checks reviews_enabled first.
 *
 * Every text column is NULLABLE and falls back to a constant on the model.
 * A cleared field therefore reverts to the shipped wording instead of rendering
 * a blank heading — an editor emptying a box must never be able to publish an
 * untitled page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_forums')) {
            return;
        }

        Schema::create('site_forums', function (Blueprint $table): void {
            $table->id();
            // Unique: one forum configuration per domain, enforced by the
            // schema rather than by every caller remembering to firstOrCreate.
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();

            $table->boolean('enabled')->default(false);

            $table->string('title', 120)->nullable();
            $table->string('eyebrow', 120)->nullable();
            $table->text('intro')->nullable();

            $table->string('meta_title', 180)->nullable();
            $table->string('meta_description', 300)->nullable();
            // Lets an editor keep the page for visitors while withholding it
            // from search engines — a thin forum is worth having and not worth
            // submitting.
            $table->boolean('noindex')->default(false);

            $table->string('empty_title', 180)->nullable();
            $table->text('empty_body')->nullable();
            $table->string('empty_cta_label', 80)->nullable();
            $table->string('empty_cta_url', 200)->nullable();

            // Whether the header shows the site-wide totals. Off is a real
            // choice while the numbers are small: "2 reviews" undersells a page
            // more than no number at all.
            $table->boolean('show_stats')->default(true);

            // Page size and how many reviews each thread previews. TINYINT
            // because the admin caps both far below 255 — see the form request.
            $table->unsignedTinyInteger('threads_per_page')->default(8);
            $table->unsignedTinyInteger('preview_reviews')->default(3);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_forums');
    }
};
