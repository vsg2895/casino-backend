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
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Writes from the public forum: posting, and reporting. */
class ForumPostController extends Controller
{
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
                'message' => $pending
                    ? ($member->isPreModerated()
                        ? 'Thanks — your first few posts are checked by a moderator before they appear.'
                        : 'Thanks — posts with links are checked by a moderator before they appear.')
                    : null,
                'post'    => $pending
                    ? null
                    : (new ForumPostResource($post->load('author:id,display_name,slug,avatar_path,approved_posts_count')))->resolve(),
            ],
        ], Response::HTTP_CREATED);
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
