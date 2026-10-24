<?php

declare(strict_types=1);

namespace App\Services\Forum;

use App\Jobs\RevalidateNextJsSites;
use App\Models\ForumModerationLog;
use App\Models\ForumPost;
use App\Models\ForumReport;
use App\Models\ForumUser;
use App\Support\SiteCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every state change a moderator makes, to posts and to members.
 *
 * Each change is logged with the acting admin. A moderation system whose
 * actions cannot be attributed is not one.
 */
class ForumModerationService
{
    /**
     * Apply one action to a batch of posts.
     *
     * One transaction for the whole batch: a bulk approve that half-applied
     * would leave counters describing a state that never existed. The public
     * cache of every touched site is flushed after it commits.
     *
     * @param  Collection<int, ForumPost>  $posts  Loaded with trashed rows included.
     * @param  'approve'|'reject'|'spam'|'delete'|'restore'  $action
     */
    public function act(Collection $posts, string $action, ?string $reason, ?int $actorId): void
    {
        $siteIds = [];

        DB::transaction(function () use ($posts, $action, $reason, $actorId, &$siteIds): void {
            foreach ($posts as $post) {
                $from = $post->status;
                $siteIds[(int) $post->site_id] = true;

                match ($action) {
                    'approve' => $this->transition($post, ForumPost::STATUS_APPROVED),
                    'reject' => $this->transition($post, ForumPost::STATUS_REJECTED),
                    'spam' => $this->markSpam($post),
                    'delete' => $post->delete(),
                    'restore' => $post->restore(),
                };

                ForumModerationLog::record(
                    (int) $post->site_id,
                    $actorId,
                    'post',
                    (int) $post->id,
                    $action,
                    $from,
                    $post->fresh()?->status,
                    $reason,
                );
            }

            // Acting on a post resolves the reports that pointed at it —
            // otherwise the reported tab keeps showing work that is done.
            ForumReport::query()
                ->whereIn('forum_post_id', $posts->pluck('id'))
                ->open()
                ->update([
                    'status' => $action === 'approve'
                        ? ForumReport::STATUS_DISMISSED
                        : ForumReport::STATUS_ACTIONED,
                    'handled_by' => $actorId,
                    'handled_at' => now(),
                ]);
        });

        foreach (array_keys($siteIds) as $siteId) {
            SiteCache::flushSite($siteId);
            RevalidateNextJsSites::dispatch(['forum'], [$siteId]);
        }
    }

    /**
     * Ban or mute a member, or lift it.
     *
     * Their posts are NOT bulk-deleted by this. Removing a person's whole
     * history on a mute is disproportionate, and on a ban it is a separate,
     * deliberate action — the moderator selects the posts and deletes them.
     */
    public function setMemberStatus(ForumUser $member, string $status, ?string $until, ?string $reason, ?int $actorId): void
    {
        $from = $member->status;

        $member->forceFill([
            'status' => $status,
            // Only a mute expires. A ban with an expiry would be a mute wearing
            // the wrong label.
            'banned_until' => $status === ForumUser::STATUS_MUTED ? $until : null,
            'ban_reason' => $status === ForumUser::STATUS_ACTIVE ? null : $reason,
        ])->save();

        if ($status === ForumUser::STATUS_BANNED) {
            // Revoke every session. A ban that leaves a live token is advice.
            $member->tokens()->delete();
        }

        ForumModerationLog::record(
            (int) $member->site_id,
            $actorId,
            'member',
            (int) $member->id,
            'status:'.$status,
            $from,
            $status,
            $reason,
        );
    }

    /**
     * Change a member's role.
     *
     * Separate from the status, because they are separate axes — see the
     * migration. Logged like every other moderation action.
     */
    public function setMemberRole(ForumUser $member, string $role, ?int $actorId): void
    {
        $from = $member->role;

        // forceFill: `role` is not fillable, precisely so that no request body
        // anywhere else can set it.
        $member->forceFill(['role' => $role])->save();

        ForumModerationLog::record(
            (int) $member->site_id,
            $actorId,
            'member',
            (int) $member->id,
            'role:'.$role,
            $from,
            $role,
        );
    }

    /**
     * Move a post to a status.
     *
     * Goes through the model so ForumPostObserver sees it — writing the column
     * with a query builder would skip every counter.
     */
    private function transition(ForumPost $post, string $status): void
    {
        // Approving something that was soft-deleted has to restore it too, or
        // the counters stay right and the post stays invisible.
        if ($status === ForumPost::STATUS_APPROVED && $post->trashed()) {
            $post->restore();
        }

        $post->status = $status;
        $post->approved_at = $status === ForumPost::STATUS_APPROVED ? now() : null;
        $post->save();
    }

    /**
     * Spam is rejected AND hidden.
     *
     * Two steps rather than one, because they mean different things: the status
     * records the judgement for the author's trust score, the soft delete takes
     * it off the page. Keeping it merely "rejected" would leave it in the
     * moderator's default view forever.
     */
    private function markSpam(ForumPost $post): void
    {
        $this->transition($post, ForumPost::STATUS_SPAM);

        if (! $post->trashed()) {
            $post->delete();
        }
    }
}
