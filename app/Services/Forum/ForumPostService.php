<?php

declare(strict_types=1);

namespace App\Services\Forum;

use App\Models\ForumArticle;
use App\Models\ForumPost;
use App\Models\ForumUser;
use App\Support\Forum\ForumContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Everything that happens when a member posts.
 *
 * The controller validates shape; this decides whether the post is allowed,
 * what it says, and whether anyone sees it before a moderator does.
 */
class ForumPostService
{
    /**
     * Create a post or a comment.
     *
     * @param  array{body: string, parent_id?: int|null}  $data
     *
     * @throws ValidationException|HttpException
     */
    public function create(
        ForumArticle $article,
        ForumUser $author,
        array $data,
        ?string $ip,
    ): ForumPost {
        $this->assertMayPost($article, $author);

        $parent = $this->resolveParent($article, $data['parent_id'] ?? null);
        $body = ForumContent::post((string) $data['body']);

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => 'Write something before posting.',
            ]);
        }

        $status = $this->decideStatus($author, $body);

        return DB::transaction(function () use ($article, $author, $parent, $body, $status, $ip): ForumPost {
            $post = new ForumPost([
                'site_id'          => $article->site_id,
                'forum_article_id' => $article->id,
                // depth is derived in the model — never passed in.
                'parent_id'        => $parent?->id,
                'forum_user_id'    => $author->id,
                'body'             => $body,
                'status'           => $status,
            ]);

            if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                // Packed binary, matching the varbinary(16) column. inet_pton
                // rather than the DB function so the value is bindable.
                $post->ip_address = inet_pton($ip);
            }

            if ($status === ForumPost::STATUS_APPROVED) {
                $post->approved_at = now();
            }

            $post->save();

            return $post;
        });
    }

    /**
     * Whether this member may write here at all.
     *
     * Ordered cheapest-first and most-specific-last, so the message a member
     * sees names the actual reason rather than the first rule that happened to
     * match.
     */
    private function assertMayPost(ForumArticle $article, ForumUser $author): void
    {
        if ($author->isSilenced()) {
            throw new HttpException(403, $author->status === ForumUser::STATUS_BANNED
                ? 'Your account cannot post on this forum.'
                : 'Your account is muted and cannot post right now.');
        }

        // Unverified addresses cannot post. Without this the whole
        // pre-moderation and trust system is bypassable by anyone willing to
        // register with a mailbox they do not own.
        if ($author->email_verified_at === null) {
            throw new HttpException(403, 'Confirm your email address before posting.');
        }

        if (! $article->acceptsPosts()) {
            throw new HttpException(422, $article->locked
                ? 'This discussion is locked — no new replies.'
                : 'This discussion is not open for replies.');
        }
    }

    /**
     * Resolve and validate the parent of a comment.
     *
     * Three things must hold, and the third is the one that is easy to miss: the
     * parent has to be a TOP-LEVEL post. Replying to a reply is what creates a
     * second level of nesting, and the brief allows exactly one.
     */
    private function resolveParent(ForumArticle $article, ?int $parentId): ?ForumPost
    {
        if ($parentId === null) {
            return null;
        }

        $parent = ForumPost::query()->find($parentId);

        if ($parent === null || $parent->forum_article_id !== $article->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'That post is not part of this discussion.',
            ]);
        }

        if ($parent->depth !== ForumPost::DEPTH_POST) {
            throw ValidationException::withMessages([
                'parent_id' => 'Replies go on the original post, not on another reply.',
            ]);
        }

        if ($parent->status !== ForumPost::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'parent_id' => 'That post is not visible.',
            ]);
        }

        return $parent;
    }

    /**
     * Pending or approved?
     *
     * Two independent gates, and a post fails to the queue if EITHER applies:
     *
     *  - the member has not yet had N posts accepted (default 3)
     *  - the post contains a link and the member is below the link threshold
     *
     * The link rule exists because the first thing a spam account does is post a
     * link, and it is the one signal that is both cheap and highly predictive.
     * A trusted member's link publishes immediately.
     */
    private function decideStatus(ForumUser $author, string $body): string
    {
        if ($author->isPreModerated()) {
            return ForumPost::STATUS_PENDING;
        }

        if (! $author->mayPostLinks() && ForumContent::containsLink($body)) {
            return ForumPost::STATUS_PENDING;
        }

        return ForumPost::STATUS_APPROVED;
    }
}
