<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\Forum\StoreForumPostRequest;
use App\Http\Resources\Forum\ForumPostResource;
use App\Jobs\RevalidateNextJsSites;
use App\Models\ForumArticle;
use App\Models\ForumPost;
use App\Models\ForumReport;
use App\Models\ForumUser;
use App\Models\Site;
use App\Services\Forum\ForumPostService;
use App\Support\Forum\ForumContent;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Writes from the public forum: posting, and reporting. */
class ForumPostController extends Controller
{
    /** Most recent posts shown on a member's own account page. */
    private const int MINE_LIMIT = 50;

    public function __construct(private readonly ForumPostService $posts) {}

    /**
     * Reply to a discussion.
     *
     * The response tells the member the truth about what happened: an approved
     * post comes back rendered, a held one comes back with `pending: true` and a
     * reason. Silently showing a held post as live is how forums end up with
     * members reposting three times because nothing appeared.
     */
    public function store(StoreForumPostRequest $request, string $site, string $slug): JsonResponse
    {
        // `$site` is the URL slug and is unused — route parameters arrive
        // positionally, so it has to be in the signature or `$slug` receives it.
        // See ForumController::category for the full note.
        $current = $this->site();

        /** @var ForumUser $member */
        $member = $request->user();

        $article = ForumArticle::query()
            ->where('site_id', $current->id)
            ->where('slug', $slug)
            ->visible()
            ->first();

        abort_if($article === null, Response::HTTP_NOT_FOUND);

        $post = $this->posts->create(
            $article,
            $member,
            $request->safe()->only(['body', 'parent_id']),
            (string) $request->ip(),
        );

        // The index shows a last-post pointer per board, so a new reply makes it
        // stale immediately. Flushed rather than left to the hour TTL — an index
        // whose "latest" is an hour old reads as an abandoned forum.
        $this->refresh($current);

        $pending = $post->status === ForumPost::STATUS_PENDING;

        return response()->json([
            'data' => [
                'pending' => $pending,
                // One message, because there is now one rule: every post is
                // reviewed. The old pair explained WHICH trust check had held
                // the post, and both of those are gone.
                'message' => $pending
                    ? 'Thanks — a moderator reviews every post before it appears.'
                    : null,
                'post'    => $pending
                    ? null
                    : (new ForumPostResource($post->load('author:id,display_name,slug,avatar_path,approved_posts_count')))->resolve(),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * The signed-in member's own posts, every status included.
     *
     * Deliberately NOT the public `approved()` scope: this is the one place a
     * member is entitled to see their own pending and rejected posts, because
     * it is the page where they wait for a decision and fix what they wrote.
     */
    public function mine(Request $request): JsonResponse
    {
        $current = $this->site();

        /** @var ForumUser $member */
        $member = $request->user();

        $posts = ForumPost::query()
            ->where('site_id', $current->id)
            ->where('forum_user_id', $member->id)
            ->orderByDesc('id')
            ->with(['article:id,title,slug,forum_category_id', 'article.category:id,name,slug'])
            ->limit(self::MINE_LIMIT)
            ->get();

        return response()->json([
            'data' => $posts->map(fn (ForumPost $p): array => [
                'id'         => (int) $p->id,
                'body'       => (string) $p->body,
                'status'     => (string) $p->status,
                // The single fact the account page's UI hangs off: a published
                // post is a matter of record and is no longer editable.
                'editable'   => $p->status === ForumPost::STATUS_PENDING,
                'created_at' => $p->created_at?->toISOString(),
                'edited_at'  => $p->edited_at?->toISOString(),
                'article'    => $p->article === null ? null : [
                    'title'    => (string) $p->article->title,
                    'slug'     => (string) $p->article->slug,
                    'category' => $p->article->category?->slug,
                    'board'    => $p->article->category?->name,
                ],
            ])->all(),
        ]);
    }

    /**
     * Edit one of your own posts — only while it is still awaiting review.
     *
     * Three separate conditions, each a different answer:
     *   - not yours            404, so the endpoint cannot be used to probe ids
     *   - yours, not pending   422 with a reason a person can act on
     *   - yours and pending    saved
     *
     * Once a moderator has published a post it is part of a conversation other
     * people have already read, so it stops being the author's to rewrite.
     */
    public function update(Request $request, string $site, int $post): JsonResponse
    {
        $current = $this->site();

        /** @var ForumUser $member */
        $member = $request->user();

        $model = ForumPost::query()
            ->where('site_id', $current->id)
            ->where('forum_user_id', $member->id)
            ->whereKey($post)
            ->first();

        abort_if($model === null, Response::HTTP_NOT_FOUND);

        if ($model->status !== ForumPost::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'body' => 'This post has already been reviewed and can no longer be edited.',
            ]);
        }

        $data = $request->validate([
            // THE canonical limit, shared with StoreForumPostRequest — an edit
            // must not be able to exceed what a post was allowed to be.
            'body'    => ['required', 'string', 'min:2', 'max:' . ForumContent::MAX_POST_LENGTH],
            'website' => ['present', 'max:0'],
        ]);

        $this->posts->updateBody($model, $data['body']);

        return response()->json([
            'data' => [
                'id'        => (int) $model->id,
                'body'      => (string) $model->body,
                'status'    => (string) $model->status,
                'editable'  => true,
                'edited_at' => $model->edited_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Report a post.
     *
     * Open to guests. Reporting is the one write a forum should never gate
     * behind registration: the person best placed to spot spam is a reader who
     * has no account and no intention of getting one.
     */
    public function report(Request $request, string $site, int $post): JsonResponse
    {
        $current = $this->site();

        $data = $request->validate([
            'reason'  => ['required', 'string', 'in:' . implode(',', ForumReport::REASONS)],
            'note'    => ['nullable', 'string', 'max:500'],
            'website' => ['present', 'max:0'],
        ]);

        $target = ForumPost::query()
            ->where('site_id', $current->id)
            ->whereKey($post)
            ->first();

        abort_if($target === null, Response::HTTP_NOT_FOUND);

        $reporterId = $request->user()?->id;

        // A member reporting the same post twice is a no-op, not an error: the
        // unique key would throw, and telling them "already reported" reveals
        // nothing they did not already do.
        ForumReport::query()->updateOrCreate(
            ['forum_post_id' => $target->id, 'forum_user_id' => $reporterId],
            [
                'site_id'     => $current->id,
                'reason'      => $data['reason'],
                'note'        => $data['note'] ?? null,
                'reporter_ip' => filter_var($request->ip(), FILTER_VALIDATE_IP) !== false
                    ? inet_pton((string) $request->ip())
                    : null,
                'status'      => ForumReport::STATUS_OPEN,
            ],
        );

        return response()->json([
            'data' => ['reported' => true, 'message' => 'Thanks — a moderator will take a look.'],
        ], Response::HTTP_CREATED);
    }

    private function refresh(Site $site): void
    {
        SiteCache::flushSite((int) $site->id);
        RevalidateNextJsSites::dispatch(['forum'], [(int) $site->id]);
    }

    private function site(): Site
    {
        /** @var Site $site */
        $site = app('current_site');

        abort_unless((bool) $site->forum_enabled, Response::HTTP_NOT_FOUND);

        return $site;
    }
}
