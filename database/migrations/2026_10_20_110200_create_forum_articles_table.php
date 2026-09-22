<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The discussion topic. Written by admins, replied to by members.
 *
 * `user_id` points at the ADMIN `users` table, not `forum_users`, and that is
 * the whole access-control story for article creation: a member has no row that
 * could ever appear in this column, so "visitors cannot create articles" is
 * enforced by which table the foreign key targets rather than by a policy
 * somebody has to remember to write.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('forum_articles')) {
            return;
        }

        Schema::create('forum_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // Restrict, not cascade: deleting a category must not silently take
            // its threads and every post under them. The admin makes the
            // operator move or delete the articles first.
            $table->foreignId('forum_category_id')->constrained('forum_categories')->restrictOnDelete();
            // The admin who wrote it. nullOnDelete so removing a colleague's
            // account never deletes published content.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 200);
            $table->string('slug', 220);
            $table->string('excerpt', 500)->nullable();
            // MEDIUMTEXT: TEXT caps at 64KB, which a long article with embedded
            // markup can reach. Sanitised on write against an allow-list.
            $table->mediumText('body');
            $table->string('cover_image_path', 255)->nullable();

            // draft / published / archived.
            $table->string('status', 12)->default('draft');
            $table->boolean('pinned')->default(false);
            // Locked keeps the thread readable and closes the reply form. It is
            // separate from `archived`, which also removes it from listings.
            $table->boolean('locked')->default(false);
            $table->timestamp('published_at')->nullable();

            // ---- denormalised counters ----
            $table->unsignedInteger('posts_count')->default(0);
            $table->unsignedBigInteger('views_count')->default(0);

            /*
             * Hot Threads rank, materialised.
             *
             * An expression — views × w1 + replies × w2 over a recent window —
             * cannot use an index and ALWAYS filesorts, however the query is
             * written. There is no index that makes an arbitrary expression
             * orderable. So the rank is computed by `forum:rescore` on a
             * schedule and stored here where it can be indexed.
             *
             * The trade is freshness: the tab is minutes behind. Given the
             * alternative is a filesort over every published article on every
             * request, minutes is the right price.
             */
            $table->unsignedInteger('hot_score')->default(0);

            // Pointer set — FKs added once forum_posts exists.
            $table->unsignedBigInteger('last_post_id')->nullable();
            $table->timestamp('last_post_at')->nullable();
            $table->unsignedBigInteger('last_post_user_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['site_id', 'slug']);

            /*
             * The category page, in its exact order: pinned first, then most
             * recently active.
             *
             * Equality columns lead (category, status), then the two sort
             * columns, then `id` to break ties and act as the keyset cursor.
             *
             * The index is ASCENDING and that is correct: the ORDER BY is
             * `pinned DESC, last_post_at DESC, id DESC` — all three the SAME
             * direction — so InnoDB satisfies it with a backward index scan.
             * EXPLAIN shows "Backward index scan; Using where" and no filesort.
             * Descending index columns would only be needed for a MIXED order
             * such as `pinned DESC, last_post_at ASC`, which this page never
             * asks for.
             *
             * `deleted_at` is not in the index: soft-deleted articles are a
             * rounding error against the total, and adding a fourth equality
             * column to widen every entry to exclude them is a worse trade than
             * letting InnoDB discard the handful it reads.
             */
            $table->index(
                ['forum_category_id', 'status', 'pinned', 'last_post_at', 'id'],
                'forum_articles_category_idx',
            );

            // Hot Threads: this site, published, best score first.
            $table->index(['site_id', 'status', 'hot_score', 'id'], 'forum_articles_hot_idx');

            // Newest articles, and the sitemap.
            $table->index(['site_id', 'status', 'published_at'], 'forum_articles_recent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_articles');
    }
};
