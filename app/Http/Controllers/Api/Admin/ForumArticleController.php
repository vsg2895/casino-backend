<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Forum\ForumArticleResource;
use App\Http\Resources\Forum\ForumPostResource;
use App\Services\Forum\ForumPostService;
use App\Jobs\RevalidateNextJsSites;
use App\Models\ForumArticle;
use App\Models\ForumModerationLog;
use App\Models\ForumPost;
use App\Models\Site;
use App\Support\Forum\ForumContent;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Discussion topics — created and managed by admins ONLY.
 *
 * "Visitors cannot create articles" needs no policy: `forum_articles.user_id`
 * targets the ADMIN users table, and no member has a row in it. The rule is
 * enforced by which table the foreign key points at.
 */
class ForumArticleController extends Controller
{
    private const int PER_PAGE = 20;

    /** Listing columns — never the body. */
    private const array LIST_COLUMNS = [
        'id', 'site_id', 'forum_category_id', 'user_id', 'title', 'slug', 'excerpt',
        'cover_image_path', 'status', 'pinned', 'locked', 'posts_count', 'views_count',
        'published_at', 'last_post_at', 'created_at', 'updated_at',
    ];

    public function index(Request $request, Site $site): JsonResponse
    {
        $filters = $request->validate([
            'category_id' => ['nullable', 'integer'],
            'status'      => ['nullable', 'string', 'in:draft,published,archived'],
            'pinned'      => ['nullable', 'boolean'],
            'from'        => ['nullable', 'date'],
            'to'          => ['nullable', 'date', 'after_or_equal:from'],
            'search'      => ['nullable', 'string', 'max:120'],
            'per_page'    => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $articles = ForumArticle::query()
            ->where('site_id', $site->id)
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('forum_category_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when(isset($filters['pinned']), fn ($q) => $q->where('pinned', (bool) $filters['pinned']))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d . ' 23:59:59'))
            // Title only, not body: the admin is looking for a thread they
            // named, and a LIKE over MEDIUMTEXT would scan every row.
            ->when($filters['search'] ?? null, fn ($q, $t) => $q->where('title', 'like', '%' . $t . '%'))
            ->with(['category:id,name,slug', 'author:id,name'])
            ->orderByDesc('pinned')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? self::PER_PAGE, self::LIST_COLUMNS);

        return response()->json([
            'data' => collect($articles->items())->map(fn (ForumArticle $a): array => [
                ...(new ForumArticleResource($a))->withRealAuthor()->resolve(),
                'status'     => $a->status,
                'created_at' => $a->created_at?->toISOString(),
            ])->all(),
            'meta' => [
                'current_page' => $articles->currentPage(),
                'last_page'    => $articles->lastPage(),
                'total'        => $articles->total(),
                'per_page'     => $articles->perPage(),
            ],
        ]);
    }

    public function show(Site $site, ForumArticle $forumArticle): JsonResponse
    {
        $this->assertOwns($site, $forumArticle);

        return response()->json([
            'data' => [
                ...(new ForumArticleResource($forumArticle->load(['category:id,name,slug', 'author:id,name'])))->withBody()->withRealAuthor()->resolve(),
                'status' => $forumArticle->status,
            ],
        ]);
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $data = $this->validated($request, $site, null);

        $article = ForumArticle::create([
            ...$data,
            'site_id' => $site->id,
            // The admin who wrote it, taken from the token — never from the
            // request body, which a client could set to anyone.
            'user_id' => $request->user()?->id,
        ]);

        $this->refresh($site);

        return response()->json(['data' => (new ForumArticleResource($article))->withBody()->withRealAuthor()->resolve()], Response::HTTP_CREATED);
    }

    public function update(Request $request, Site $site, ForumArticle $forumArticle): JsonResponse
    {
        $this->assertOwns($site, $forumArticle);

        $forumArticle->update($this->validated($request, $site, $forumArticle));

        $this->refresh($site);

        return response()->json(['data' => (new ForumArticleResource($forumArticle))->withBody()->withRealAuthor()->resolve()]);
    }

    /**
     * Delete an article and everything under it.
     *
     * SOFT, and the posts go soft with it — one transaction, so a reader can
     * never see a thread whose article has gone. Each post is deleted through
     * the model rather than with a mass update, because a mass update fires no
     * events and every counter would be left describing posts that are no
     * longer visible.
     *
     * That is the expensive choice and it is the correct one. The alternative
     * is a bulk UPDATE plus a recount, which is faster and leaves a window where
     * the numbers are wrong.
     */
    /**
     * Reply to a discussion as the editorial team.
     *
     * The post is stored against the authenticated admin's real `users` row —
     * taken from the token, never from the body — and published approved. It
     * renders publicly under the site's team name; nothing writes that name to
     * the database.
     *
     * This is the ONLY way a staff reply is created. Members continue to post
     * through the public endpoint exactly as before, and nothing here touches
     * that path.
     */
    public function storePost(Request $request, Site $site, ForumArticle $forumArticle, ForumPostService $posts): JsonResponse
    {
        $this->assertOwns($site, $forumArticle);

        $data = $request->validate([
            'body'      => ['required', 'string', 'max:20000'],
            // A reply to a reply. The service refuses a third level.
            'parent_id' => ['nullable', 'integer'],
        ]);

        $admin = $request->user();

        abort_if($admin === null, Response::HTTP_UNAUTHORIZED);

        $post = $posts->createAsTeam($forumArticle, $admin, $data);

        $this->refresh($site);

        return response()->json(
            ['data' => (new ForumPostResource($post->load('site')))->resolve()],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Edit a staff reply.
     *
     * Restricted to posts the team wrote. A member's words are theirs: the
     * moderation queue can approve, reject or remove one, but nothing in this
     * application may rewrite it and leave it attributed to them.
     */
    public function updatePost(Request $request, Site $site, ForumArticle $forumArticle, ForumPost $forumPost, ForumPostService $posts): JsonResponse
    {
        $this->assertOwns($site, $forumArticle);

        abort_if($forumPost->forum_article_id !== $forumArticle->id, Response::HTTP_NOT_FOUND);
        abort_unless($forumPost->isStaffAuthored(), Response::HTTP_FORBIDDEN, 'Only the editorial team\'s own replies can be edited here.');

        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);

        $post = $posts->updateBody($forumPost, $data['body']);

        $this->refresh($site);

        return response()->json(['data' => (new ForumPostResource($post->load('site')))->resolve()]);
    }

    public function destroy(Request $request, Site $site, ForumArticle $forumArticle): JsonResponse
    {
        $this->assertOwns($site, $forumArticle);

        $deleted = DB::transaction(function () use ($forumArticle, $request): int {
            $n = 0;

            ForumPost::query()
                ->where('forum_article_id', $forumArticle->id)
                ->orderBy('id')
                // Chunked: a thread with 50,000 replies must not be loaded into
                // memory to be deleted.
                ->chunkById(500, function ($posts) use (&$n): void {
                    foreach ($posts as $post) {
                        $post->delete();
                        $n++;
                    }
                });

            $forumArticle->delete();

            ForumModerationLog::record(
                (int) $forumArticle->site_id,
                $request->user()?->id,
                'article',
                (int) $forumArticle->id,
                'delete',
                $forumArticle->status,
                null,
                "Deleted with {$n} post(s).",
            );

            return $n;
        });

        $this->refresh($site);

        return response()->json([
            'deleted' => true,
            'posts_removed' => $deleted,
            'message' => $deleted === 0
                ? 'Discussion deleted.'
                : "Discussion deleted, along with {$deleted} post(s).",
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Site $site, ?ForumArticle $existing): array
    {
        $data = $request->validate([
            'forum_category_id' => [
                'required', 'integer',
                Rule::exists('forum_categories', 'id')->where('site_id', $site->id),
            ],
            'title'  => ['required', 'string', 'max:200'],
            'slug'   => [
                'nullable', 'string', 'max:220', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('forum_articles', 'slug')
                    ->where('site_id', $site->id)
                    ->ignore($existing?->id),
            ],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body'    => ['required', 'string', 'max:200000'],
            'cover_image_path' => ['nullable', 'string', 'max:255'],
            'status'  => ['required', 'string', 'in:draft,published,archived'],
            'pinned'  => ['sometimes', 'boolean'],
            'locked'  => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
        ]);

        // Admin-authored HTML, still filtered — an admin account can be taken
        // over, and a stored script in an article body runs for every reader.
        $data['body'] = ForumContent::articleBody($data['body']);

        if (($data['slug'] ?? null) === null || $data['slug'] === '') {
            $data['slug'] = $this->uniqueSlug($site, $data['title'], $existing);
        }

        return $data;
    }

    private function uniqueSlug(Site $site, string $title, ?ForumArticle $existing): string
    {
        $base = Str::slug($title) ?: 'discussion';
        $slug = $base;
        $n = 1;

        while (ForumArticle::withTrashed()
            ->where('site_id', $site->id)
            ->where('slug', $slug)
            ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->id))
            ->exists()) {
            $slug = $base . '-' . ++$n;
        }

        return $slug;
    }

    private function assertOwns(Site $site, ForumArticle $article): void
    {
        abort_if((int) $article->site_id !== (int) $site->id, Response::HTTP_NOT_FOUND);
    }

    private function refresh(Site $site): void
    {
        SiteCache::flushSite((int) $site->id);
        RevalidateNextJsSites::dispatch(['forum'], [(int) $site->id]);
    }
}
