<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\Forum\ForumArticleResource;
use App\Http\Resources\Forum\ForumCategoryResource;
use App\Http\Resources\Forum\ForumPostResource;
use App\Models\ForumArticle;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\Site;
use App\Services\Forum\ForumIndexService;
use App\Support\Forum\ForumViewCounter;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public community forum.
 *
 * Every route 404s unless the site has `forum_enabled` — enforced here, not left
 * to the front end, because a disabled section must not be readable by anyone
 * who guesses the URL.
 *
 * ── Pagination ──────────────────────────────────────────────────────────────
 *
 * The post list is KEYSET paginated on `id`. The category listing is offset
 * paginated and that is not an inconsistency: a category holds hundreds of
 * articles and an editor needs to reach page 7 of them, while a thread holds
 * thousands of posts read front-to-back. The table that grows fastest gets the
 * pagination that does not degrade.
 */
class ForumController extends Controller
{
    private const int ARTICLES_PER_PAGE = 20;

    private const int POSTS_PER_PAGE = 20;

    private const int LATEST_LIMIT = 10;

    private const int HOT_LIMIT = 10;

    /** Ceiling for the account page's discussion picker — a <select>, not a feed. */
    private const int DISCUSSION_PICKER_LIMIT = 200;

    /** Listing columns — never the body. */
    private const array ARTICLE_LIST_COLUMNS = [
        'id', 'site_id', 'forum_category_id', 'user_id', 'title', 'slug', 'excerpt',
        'cover_image_path', 'pinned', 'locked', 'posts_count', 'views_count',
        'published_at', 'last_post_at', 'last_post_user_id',
    ];

    public function __construct(private readonly ForumIndexService $index) {}

    /**
     * The forum index: sections with their boards, plus the two other tabs.
     *
     * Cached for an hour per site. The last-post pointers change on every reply,
     * so the cache is flushed by the post service rather than left to expire —
     * an index showing an hour-old "last post" reads as a dead forum.
     */
    public function index(Request $request): JsonResponse
    {
        $site = $this->site();

        $payload = SiteCache::remember($site->id, ['forum'], "forum:index:{$site->id}", 3600, function () use ($site): array {
            $built = $this->index->build((int) $site->id);

            return [
                'sections' => array_map(
                    fn (array $section): array => [
                        ...$section,
                        'categories' => ForumCategoryResource::collection($section['categories'])->resolve(),
                    ],
                    $built['sections'],
                ),
                'stats'  => $built['stats'],
                'latest' => $this->latestPosts($site),
                'hot'    => $this->hotThreads($site),
            ];
        });

        return response()->json(['data' => $payload]);
    }

    /**
     * One board, with its articles. Pinned first, then most recently active.
     *
     * `$site` is the slug from the URL and is deliberately UNUSED — the real
     * site comes from the container, where VerifySiteAccess bound it after
     * checking the key. It is in the signature because Laravel passes route
     * parameters to a controller POSITIONALLY, not by name: without it, the
     * first argument after the Request is the site slug and every board 404s.
     * The same convention is already in ArticleController and NewsController.
     */
    public function category(Request $request, string $site, string $categorySlug): JsonResponse
    {
        $current = $this->site();

        $category = ForumCategory::query()
            ->where('site_id', $current->id)
            ->where('slug', $categorySlug)
            ->active()
            ->first();

        abort_if($category === null, Response::HTTP_NOT_FOUND);

        $articles = ForumArticle::query()
            ->where('forum_category_id', $category->id)
            ->visible()
            ->orderByDesc('pinned')
            ->orderByDesc('last_post_at')
            ->orderByDesc('id')
            ->paginate(self::ARTICLES_PER_PAGE, self::ARTICLE_LIST_COLUMNS)
            ->withQueryString();

        return response()->json([
            'data' => [
                'category' => (new ForumCategoryResource($category))->resolve(),
                'articles' => ForumArticleResource::collection($articles->items())->resolve(),
            ],
            'meta' => [
                'current_page' => $articles->currentPage(),
                'last_page'    => $articles->lastPage(),
                'total'        => $articles->total(),
                'per_page'     => $articles->perPage(),
            ],
        ]);
    }

    /**
     * One discussion, with a page of its posts and their comments.
     *
     * `?after=<id>` is the keyset cursor. It is the id of the last post on the
     * previous page, so the next page is `id > cursor` — a seek, not a skip.
     */
    public function article(Request $request, string $site, string $categorySlug, string $slug): JsonResponse
    {
        $current = $this->site();

        $article = ForumArticle::query()
            ->where('site_id', $current->id)
            ->where('slug', $slug)
            ->visible()
            ->with(['category:id,name,slug', 'author:id,name'])
            ->first();

        abort_if($article === null, Response::HTTP_NOT_FOUND);
        // The category is part of the URL, so a mismatched one is a wrong URL
        // rather than a redirect target — two paths for one page is the
        // duplicate-content problem the canonical tag exists to avoid.
        abort_if($article->category?->slug !== $categorySlug, Response::HTTP_NOT_FOUND);

        $cursor = $this->cursor($request);

        $posts = ForumPost::query()
            ->where('forum_article_id', $article->id)
            ->approved()
            ->topLevel()
            ->when($cursor !== null, fn ($q) => $q->where('id', '>', $cursor))
            ->orderBy('id')
            // One extra row, which is how "is there a next page" is answered
            // without a COUNT over the thread.
            ->limit(self::POSTS_PER_PAGE + 1)
            ->with(['author:id,display_name,slug,avatar_path,approved_posts_count'])
            ->get();

        $hasMore = $posts->count() > self::POSTS_PER_PAGE;
        $posts = $posts->take(self::POSTS_PER_PAGE);

        $this->attachComments($posts);
        $this->countView($request, $article);

        return response()->json([
            'data' => [
                'article' => (new ForumArticleResource($article))->withBody()->resolve(),
                'posts'   => ForumPostResource::collection($posts)->resolve(),
            ],
            'meta' => [
                // Opaque to the client by convention: it is the id, but nothing
                // outside this controller is entitled to assume that.
                'next_cursor' => $hasMore ? (string) $posts->last()?->id : null,
                'prev_cursor' => $cursor === null ? null : (string) $posts->first()?->id,
                'per_page'    => self::POSTS_PER_PAGE,
            ],
        ]);
    }

    /**
     * Load one level of replies for a whole page of posts.
     *
     * One query with `parent_id IN (…)`, ordered by (parent_id, id) so
     * forum_posts_replies_idx delivers them already sorted — ordering by `id`
     * alone across several ranges is the one shape that reintroduces a sort.
     */
    private function attachComments(\Illuminate\Support\Collection $posts): void
    {
        $ids = $posts->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        $comments = ForumPost::query()
            ->whereIn('parent_id', $ids)
            ->approved()
            ->orderBy('parent_id')
            ->orderBy('id')
            ->with(['author:id,display_name,slug,avatar_path,approved_posts_count'])
            ->get()
            ->groupBy('parent_id');

        foreach ($posts as $post) {
            $post->setRelation('comments', $comments->get($post->id, collect()));
        }
    }

    /**
     * Every discussion a member may post into, flat and grouped by board.
     *
     * Exists for one screen: the account page's "which discussion?" picker.
     * Deliberately thin — id, title, slug and the board it sits in — because a
     * picker needs nothing else and this is a public, unauthenticated read.
     *
     * LOCKED discussions are excluded. Offering one in the picker would let a
     * member write a post that `assertMayPost()` then refuses, which reads as
     * a broken form rather than a closed thread.
     */
    public function discussions(Request $request, string $site): JsonResponse
    {
        $current = $this->site();

        $rows = SiteCache::remember(
            $current->id,
            ['forum'],
            'forum:discussions:site:' . $current->id,
            600,
            fn (): array => ForumArticle::query()
                ->where('site_id', $current->id)
                ->visible()
                ->where('locked', false)
                ->orderByDesc('last_post_at')
                ->orderByDesc('id')
                ->with(['category:id,name,slug'])
                ->select(['id', 'title', 'slug', 'forum_category_id', 'last_post_at'])
                ->limit(self::DISCUSSION_PICKER_LIMIT)
                ->get()
                ->map(fn (ForumArticle $a): array => [
                    'id'    => (int) $a->id,
                    'title' => (string) $a->title,
                    'slug'  => (string) $a->slug,
                    'board' => $a->category === null ? null : [
                        'name' => (string) $a->category->name,
                        'slug' => (string) $a->category->slug,
                    ],
                ])
                ->all(),
        );

        return response()->json(['data' => $rows]);
    }

    /** Newest approved posts across the whole forum. */
    private function latestPosts(Site $site): array
    {
        return ForumPost::query()
            ->where('site_id', $site->id)
            ->approved()
            ->orderByDesc('id')
            ->limit(self::LATEST_LIMIT)
            ->with(['author:id,display_name,slug', 'article:id,title,slug,forum_category_id', 'article.category:id,slug'])
            ->select(['id', 'site_id', 'forum_user_id', 'user_id', 'forum_article_id', 'body', 'created_at'])
            ->get()
            ->map(fn (ForumPost $p): array => [
                'id'         => (int) $p->id,
                // A one-line teaser, not the post. The tab links through.
                'excerpt'    => \Illuminate\Support\Str::limit($p->body, 140),
                'created_at' => $p->created_at?->toISOString(),
                // A staff reply has no member row, so it publishes under the
                // site's team name — the same name its discussion opener does.
                'author'     => $p->isStaffAuthored()
                    ? \App\Support\Forum\ForumTeamName::for($site)
                    : $p->author?->display_name,
                'article'    => $p->article === null ? null : [
                    'title'    => $p->article->title,
                    'slug'     => $p->article->slug,
                    'category' => $p->article->category?->slug,
                ],
            ])
            ->all();
    }

    /** Articles ranked by the materialised hot score. */
    private function hotThreads(Site $site): array
    {
        return ForumArticle::query()
            ->where('site_id', $site->id)
            ->visible()
            ->orderByDesc('hot_score')
            ->orderByDesc('id')
            ->limit(self::HOT_LIMIT)
            ->with('category:id,slug,name')
            ->get(['id', 'title', 'slug', 'forum_category_id', 'posts_count', 'views_count', 'last_post_at'])
            ->map(fn (ForumArticle $a): array => [
                'id'          => (int) $a->id,
                'title'       => $a->title,
                'slug'        => $a->slug,
                'category'    => $a->category?->slug,
                'posts_count' => (int) $a->posts_count,
                'views_count' => (int) $a->views_count,
            ])
            ->all();
    }

    /** `?after=` as a positive integer, or null. */
    private function cursor(Request $request): ?int
    {
        $raw = $request->query('after');

        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        return ctype_digit((string) $raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    /**
     * Record the view, but only for the first page of a thread.
     *
     * Paging through a long discussion is one reading of it, not twelve.
     */
    private function countView(Request $request, ForumArticle $article): void
    {
        if ($this->cursor($request) !== null) {
            return;
        }

        ForumViewCounter::record(
            (int) $article->id,
            ForumViewCounter::visitorKey(null, (string) $request->ip(), (string) $request->userAgent()),
        );
    }

    private function site(): Site
    {
        /** @var Site $site */
        $site = app('current_site');

        abort_unless((bool) $site->forum_enabled, Response::HTTP_NOT_FOUND);

        return $site;
    }
}
