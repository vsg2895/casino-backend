<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Posts and their comments — one table, one level of nesting.
 *
 * A comment is a post with a `parent_id`. Splitting them into two tables would
 * double every moderation query, every counter and every rate limit for a
 * distinction that is one nullable column.
 *
 * ── Depth is enforced by the database, not only by the domain layer ─────────
 *
 * `depth` is redundant with `parent_id` on purpose. Two CHECK constraints tie
 * them together, and MySQL 9 enforces CHECKs for real:
 *
 *   depth IN (0, 1)                       — no third level can ever exist
 *   (depth = 0) = (parent_id IS NULL)     — the two can never disagree
 *
 * The service layer refuses a reply-to-a-reply with a readable error. These
 * constraints are what happens when the service is wrong: a bug becomes a
 * failed INSERT instead of a three-deep thread nobody notices for a month.
 *
 * ── This is the table that grows ────────────────────────────────────────────
 *
 * Every index below is chosen for tens of millions of rows. At the 50,000 the
 * acceptance seed creates, all of them are over-engineered, which is the point.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('forum_posts')) {
            return;
        }

        Schema::create('forum_posts', function (Blueprint $table): void {
            $table->id();
            // Denormalised from the article, like site_id on forum_categories.
            // The Latest Posts feed and the moderation queue are both
            // site-scoped and must not join to forum_articles to be so.
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('forum_article_id')->constrained('forum_articles')->cascadeOnDelete();

            // Self-reference. cascadeOnDelete would let a hard-deleted parent
            // take its replies; that is what soft deletes are for, and the
            // service re-parents nothing — a deleted parent keeps its children
            // visible under a tombstone, as every readable forum does.
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedTinyInteger('depth')->default(0);

            $table->foreignId('forum_user_id')->constrained('forum_users')->cascadeOnDelete();

            $table->text('body');

            // pending / approved / rejected / spam.
            $table->string('status', 12)->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();

            // VARBINARY(16) via INET6_ATON: 16 bytes rather than 45, and correct
            // for IPv6, which a varchar(45) only pretends to be.
            $table->binary('ip_address', 16)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_id')->references('id')->on('forum_posts')->cascadeOnDelete();

            /*
             * The article page, keyset paginated.
             *
             * (article, status, depth) are all equality; `id` is the cursor and
             * the sort. `WHERE … AND id > :cursor ORDER BY id LIMIT 20` seeks
             * straight to the cursor's position — page 500 costs what page 1
             * costs, which OFFSET 10000 does not.
             *
             * `id` rather than `created_at`: it is unique, monotonic, already
             * the primary key, and needs no tiebreaker. Two posts created in the
             * same second under a created_at cursor can repeat or vanish across
             * a page boundary.
             */
            $table->index(['forum_article_id', 'status', 'depth', 'id'], 'forum_posts_thread_idx');

            // One level of replies for a whole page of posts, in one
            // `WHERE parent_id IN (…) ORDER BY id` rather than 20 queries.
            $table->index(['parent_id', 'id'], 'forum_posts_replies_idx');

            /*
             * Latest Posts AND the moderation queue, from one index.
             *
             * The feed reads status='approved', the queue reads status='pending'
             * — same shape, same order, different constant. A second index would
             * be pure write cost for no read the first one cannot serve.
             */
            $table->index(['site_id', 'status', 'id'], 'forum_posts_feed_idx');

            // A member's own posts; the admin's per-user drill-down.
            $table->index(['forum_user_id', 'id'], 'forum_posts_author_idx');

            // Per-IP rate limiting and spam forensics: "how many posts from this
            // address in the last hour".
            $table->index(['ip_address', 'created_at'], 'forum_posts_ip_idx');
        });

        // Laravel's schema builder has no fluent CHECK, and these are the
        // constraints that make one-level nesting structurally impossible
        // rather than merely discouraged.
        DB::statement('ALTER TABLE forum_posts ADD CONSTRAINT forum_posts_depth_range CHECK (depth IN (0, 1))');
        DB::statement('ALTER TABLE forum_posts ADD CONSTRAINT forum_posts_depth_matches_parent CHECK ((depth = 0 AND parent_id IS NULL) OR (depth = 1 AND parent_id IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_posts');
    }
};
