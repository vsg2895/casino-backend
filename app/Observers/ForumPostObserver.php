<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ForumArticle;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumUser;
use App\Support\Forum\ForumCounters;
use Illuminate\Support\Facades\DB;

/**
 * Keeps every denormalised total honest.
 *
 * ── The rule this file implements ───────────────────────────────────────────
 *
 * A post counts toward the totals when it is APPROVED and NOT soft-deleted.
 * Every transition into or out of that state is a +1 or a -1, and there are
 * more ways to cross that line than are obvious:
 *
 *   created already approved   → +1        (an auto-published post)
 *   created pending            →  0        (waits in the queue)
 *   pending  → approved        → +1
 *   approved → rejected/spam   → -1
 *   approved → soft deleted    → -1
 *   soft deleted → restored    → +1  but only if it is still approved
 *   force deleted while approved → -1
 *
 * Getting "restore" wrong is the classic bug: restoring a REJECTED post must
 * not increment anything, because it never counted in the first place.
 *
 * ── Why the writes look like this ───────────────────────────────────────────
 *
 * Every mutation runs inside a transaction with the write that caused it, and
 * every counter moves with an atomic `increment`/`decrement` — a read-then-write
 * would lose updates under two concurrent posts on the same article.
 *
 * The last-post pointers are recomputed rather than assigned, because "the most
 * recent approved post" after a deletion is a query, not the row we happen to
 * be holding.
 */
class ForumPostObserver
{
    public function created(ForumPost $post): void
    {
        // Two different tallies move on two different conditions, and conflating
        // them is how `approved_posts_count` ends up meaning nothing:
        //
        //   posts_count          — every post the member has written
        //   approved_posts_count — the ones that survived moderation
        //
        // Only the second gates pre-moderation and link permission, so only the
        // second is allowed to require approval.
        $this->adjustAuthored($post, 1);

        if ($post->countsTowardTotals()) {
            $this->apply($post, 1);
        }
    }

    /**
     * Status changes, and soft deletes.
     *
     * Laravel fires `updated` for a soft delete too, so both paths arrive here
     * and both are handled by comparing what the row counted for BEFORE this
     * save with what it counts for now.
     */
    public function updated(ForumPost $post): void
    {
        // A restore nulls `deleted_at` through save(), so it arrives here. A soft
        // DELETE does not — Laravel writes that with a query builder update,
        // which fires no model event but `deleted`.
        $wasDeleted = $post->getOriginal('deleted_at') !== null;
        $isDeleted = $post->deleted_at !== null;

        if ($wasDeleted !== $isDeleted) {
            $this->adjustAuthored($post, $isDeleted ? -1 : 1);
        }

        $countedBefore = $this->countedBefore($post);
        $countsNow = $post->countsTowardTotals();

        if ($countedBefore === $countsNow) {
            // It may still have moved article — a moderator re-filing a post is
            // rare but real, and the counters have to follow it.
            if ($countsNow && $post->wasChanged('forum_article_id')) {
                $this->moveBetweenArticles($post);
            }

            return;
        }

        $this->apply($post, $countsNow ? 1 : -1);
    }

    /** Soft delete. `updated` does not fire for `delete()` on a SoftDeletes model. */
    public function deleted(ForumPost $post): void
    {
        if ($post->isForceDeleting()) {
            return;
        }

        $this->adjustAuthored($post, -1);

        // `deleted_at` is set on the in-memory model by now, so
        // countsTowardTotals() is already false — decide on the status alone.
        if ($post->status === ForumPost::STATUS_APPROVED) {
            $this->apply($post, -1);
        }
    }

    /**
     * Deliberately does NOT touch the counters.
     *
     * `restore()` nulls `deleted_at` and calls save(), so `updated` fires first
     * and already sees the transition: it counted nothing before (deleted_at was
     * set) and counts now, so it applies the +1. Incrementing again here
     * double-counted every restore — measured, not theorised.
     *
     * It also gets the rejected case right for free: restoring a REJECTED post
     * leaves `countsNow` false, so `updated` correctly does nothing, where a
     * status check in this method would have had to repeat that logic.
     *
     * Kept as an explicit empty method rather than deleted, so the next person
     * to look for the restore path finds this note instead of concluding it was
     * forgotten.
     */
    public function restored(ForumPost $post): void
    {
        // Intentionally empty — see the docblock.
    }

    public function forceDeleted(ForumPost $post): void
    {
        // A force delete of an ALREADY soft-deleted post must not decrement
        // twice — `deleted` already took it off the books.
        if ($post->getOriginal('deleted_at') !== null) {
            return;
        }

        $this->adjustAuthored($post, -1);

        if ($post->status === ForumPost::STATUS_APPROVED) {
            $this->apply($post, -1);
        }
    }

    /**
     * Whether the row counted before the save that just happened.
     *
     * Reads the ORIGINAL attributes, which Eloquent still holds after an update
     * — that is what makes the +1/-1 decision a comparison rather than a guess.
     */
    private function countedBefore(ForumPost $post): bool
    {
        return $post->getOriginal('status') === ForumPost::STATUS_APPROVED
            && $post->getOriginal('deleted_at') === null;
    }

    /**
     * Move a counted post from one article to another.
     *
     * Two articles and possibly two categories change, so it is a -1 and a +1
     * rather than a no-op.
     */
    private function moveBetweenArticles(ForumPost $post): void
    {
        $from = (int) $post->getOriginal('forum_article_id');
        $to = (int) $post->forum_article_id;

        DB::transaction(function () use ($post, $from, $to): void {
            ForumCounters::adjustArticle($from, -1);
            ForumCounters::adjustArticle($to, 1);
            ForumCounters::refreshArticlePointer($from);
            ForumCounters::refreshArticlePointer($to);
            ForumCounters::refreshCategoryFor($from);
            ForumCounters::refreshCategoryFor($to);
            ForumCounters::refreshCategoryFor($post->forum_article_id);
        });
    }

    /**
     * Move the member's "posts written" tally.
     *
     * Counts rows that exist — a soft-deleted post is not one the member has
     * written any more, and restoring it makes it one again. Deliberately blind
     * to `status`: a post awaiting review was still written.
     */
    private function adjustAuthored(ForumPost $post, int $delta): void
    {
        ForumUser::query()
            ->whereKey($post->forum_user_id)
            ->update([
                // CASE, not GREATEST: GREATEST is MySQL-only and the suite
                // runs on SQLite. Same clamp, same single atomic statement.
                'posts_count' => DB::raw(
                    $delta > 0
                        ? 'posts_count + 1'
                        : 'CASE WHEN posts_count >= 1 THEN posts_count - 1 ELSE 0 END',
                ),
            ]);
    }

    /**
     * Apply a single +1 / -1 across the article, its category and the author.
     *
     * One transaction, so a crash can never leave the article counted and the
     * category not.
     */
    private function apply(ForumPost $post, int $delta): void
    {
        DB::transaction(function () use ($post, $delta): void {
            $articleId = (int) $post->forum_article_id;

            ForumCounters::adjustArticle($articleId, $delta);
            ForumCounters::refreshArticlePointer($articleId);
            ForumCounters::refreshCategoryFor($articleId);

            // The member's accepted-post tally — what pre-moderation and link
            // permission both read. `posts_count` is every post they ever wrote
            // and only moves on create/destroy.
            ForumUser::query()
                ->whereKey($post->forum_user_id)
                ->update([
                    'approved_posts_count' => DB::raw(
                        $delta > 0
                            ? 'approved_posts_count + 1'
                            : 'CASE WHEN approved_posts_count >= 1 THEN approved_posts_count - 1 ELSE 0 END',
                    ),
                ]);
        });
    }
}
