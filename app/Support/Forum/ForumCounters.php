<?php

declare(strict_types=1);

namespace App\Support\Forum;

use App\Models\ForumArticle;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use Illuminate\Support\Facades\DB;

/**
 * The only place a denormalised forum counter is written.
 *
 * Every statement here is a single atomic UPDATE. Nothing reads a counter,
 * adds one in PHP and writes it back: two members replying to the same article
 * in the same instant would then each read N and each write N+1, and the
 * article would permanently be one short. `posts_count + 1` in SQL cannot lose
 * that race.
 *
 * Decrements are clamped to zero against an UNSIGNED column. That is not
 * defensive decoration — an unsigned underflow in MySQL either errors or wraps
 * to 18 quintillion depending on strict mode, and a counter that wraps is worse
 * than one that is briefly wrong. If a clamp ever fires, `forum:recount` is the
 * repair.
 *
 * The clamp is a CASE expression rather than GREATEST(). GREATEST is MySQL's
 * scalar form and does not exist in SQLite, which is what the test suite runs
 * on — so the production code was correct and every counter test errored. CASE
 * is standard SQL, works on both, and is still one atomic statement.
 */
final class ForumCounters
{
    /** Move one article's post total. */
    public static function adjustArticle(int $articleId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        ForumArticle::query()
            ->whereKey($articleId)
            ->update([
                'posts_count' => DB::raw(
                    $delta > 0
                        ? 'posts_count + ' . $delta
                        : 'CASE WHEN posts_count >= ' . abs($delta) . ' THEN posts_count - ' . abs($delta) . ' ELSE 0 END',
                ),
            ]);
    }

    /**
     * Recompute one article's last-post pointer.
     *
     * A query rather than an assignment: after a deletion the answer is "the
     * next most recent approved post", which the row being deleted cannot tell
     * us. Uses forum_posts_thread_idx and reads exactly one row.
     */
    public static function refreshArticlePointer(int $articleId): void
    {
        $last = ForumPost::query()
            ->where('forum_article_id', $articleId)
            ->where('status', ForumPost::STATUS_APPROVED)
            ->orderByDesc('id')
            ->first(['id', 'forum_user_id', 'created_at']);

        ForumArticle::query()->whereKey($articleId)->update([
            'last_post_id'      => $last?->id,
            'last_post_at'      => $last?->created_at,
            'last_post_user_id' => $last?->forum_user_id,
        ]);
    }

    /** Recompute the category owning this article. */
    public static function refreshCategoryFor(int $articleId): void
    {
        $categoryId = ForumArticle::withTrashed()
            ->whereKey($articleId)
            ->value('forum_category_id');

        if ($categoryId !== null) {
            self::refreshCategory((int) $categoryId);
        }
    }

    /**
     * Rebuild one category's totals from its articles.
     *
     * Reads `forum_articles`, NEVER `forum_posts`. That is the point of the
     * whole design: the category's totals are a sum over its articles' already
     * maintained counters — hundreds of rows — rather than a COUNT over
     * millions of posts.
     */
    public static function refreshCategory(int $categoryId): void
    {
        $totals = ForumArticle::query()
            ->where('forum_category_id', $categoryId)
            ->selectRaw('COUNT(*) AS articles_count, COALESCE(SUM(posts_count), 0) AS posts_count')
            ->first();

        // The category's last post is the newest among its articles' pointers —
        // again from forum_articles, not from forum_posts.
        $last = ForumArticle::query()
            ->where('forum_category_id', $categoryId)
            ->whereNotNull('last_post_id')
            ->orderByDesc('last_post_at')
            ->orderByDesc('last_post_id')
            ->first(['id', 'last_post_id', 'last_post_at', 'last_post_user_id']);

        ForumCategory::query()->whereKey($categoryId)->update([
            'articles_count'       => (int) ($totals->articles_count ?? 0),
            'posts_count'          => (int) ($totals->posts_count ?? 0),
            'last_post_id'         => $last?->last_post_id,
            'last_post_at'         => $last?->last_post_at,
            'last_post_user_id'    => $last?->last_post_user_id,
            'last_post_article_id' => $last?->id,
        ]);
    }
}
