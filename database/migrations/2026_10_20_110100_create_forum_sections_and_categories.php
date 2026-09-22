<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The top two levels: Section → Category.
 *
 * Both are small, admin-authored and read on every forum index request. They
 * are in one migration because a category is meaningless without its section
 * and splitting them would let a half-applied deploy leave an orphan FK.
 *
 * ── The counters are the whole point of this file ───────────────────────────
 *
 * The forum index must render "N posts in M articles" and the last post for
 * every category WITHOUT the query ever touching `forum_posts`. That table is
 * the one that grows to millions of rows; a COUNT() or a correlated subquery
 * against it, once per category, once per pageview, is the classic forum
 * performance failure.
 *
 * So the numbers live here, written by observers inside the same transaction as
 * the post that changes them, and `forum:recount` rebuilds them from scratch if
 * they ever drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('forum_sections')) {
            Schema::create('forum_sections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained()->cascadeOnDelete();

                $table->string('name', 120);
                $table->string('slug', 140);
                $table->string('description', 255)->nullable();
                $table->unsignedSmallInteger('position')->default(0);
                $table->boolean('active')->default(true);

                $table->timestamps();

                $table->unique(['site_id', 'slug']);
                // The index page reads sections in display order. Tens of rows
                // ever, but the index also removes the filesort, and a sort this
                // page performs on every request is worth one small B-tree.
                $table->index(['site_id', 'active', 'position'], 'forum_sections_display_idx');
            });
        }

        if (Schema::hasTable('forum_categories')) {
            return;
        }

        Schema::create('forum_categories', function (Blueprint $table): void {
            $table->id();
            /*
             * site_id is DENORMALISED from the parent section, deliberately.
             *
             * Two reasons, both structural. It lets "one slug per site" be a
             * schema constraint instead of an application check — a category
             * slug is in the public URL. And it keeps the index page's query
             * single-table: without it, scoping categories to a site would need
             * a join to forum_sections on every request.
             *
             * The cost is that a section may never change site. Nothing in the
             * admin offers that, and ForumCategoryObserver asserts it.
             */
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('forum_section_id')->constrained('forum_sections')->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('slug', 140);
            $table->string('description', 500)->nullable();
            // A short token the front end maps to one of its own SVGs. NOT a
            // path or markup: an admin-supplied icon string that reaches the DOM
            // is a stored-XSS hole for the sake of a decoration.
            $table->string('icon', 40)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);

            // ---- denormalised counters ----
            $table->unsignedInteger('articles_count')->default(0);
            $table->unsignedBigInteger('posts_count')->default(0);

            /*
             * The "last post" pointer set. Four columns instead of a join,
             * because the index page shows the thread title, the author and the
             * time for every category and must not read forum_posts to do it.
             *
             * Declared here as plain columns; the foreign keys are added in
             * ..._120000_add_forum_last_post_foreign_keys once every table
             * exists. The three tables are mutually referential — a category
             * points at a post, a post at an article, an article back at a
             * category — so no single CREATE order can satisfy all of them.
             */
            $table->unsignedBigInteger('last_post_id')->nullable();
            $table->timestamp('last_post_at')->nullable();
            $table->unsignedBigInteger('last_post_user_id')->nullable();
            $table->unsignedBigInteger('last_post_article_id')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'slug']);
            // The index page: this site's categories, in display order, grouped
            // by section. Section before position because the page renders one
            // block per section.
            $table->index(['site_id', 'active', 'forum_section_id', 'position'], 'forum_categories_display_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_categories');
        Schema::dropIfExists('forum_sections');
    }
};
