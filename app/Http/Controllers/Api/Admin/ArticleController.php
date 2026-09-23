<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreArticleRequest;
use App\Http\Requests\Admin\UpdateArticleRequest;
use App\Http\Resources\ArticleResource;
use App\Jobs\RevalidateNextJsSites;
use App\Models\Article;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Guides for one site.
 *
 * Nested under the site because an article belongs to exactly one domain — the
 * only content type in this application for which that is true.
 *
 * The admin listing shows DRAFTS as well as published articles; the public one
 * cannot. That asymmetry is the point of having a draft state at all.
 */
class ArticleController extends Controller
{
    /** Rows per page in the admin listing. */
    private const int PER_PAGE = 15;

    /** @var list<string> The columns a listing row needs — never the body. */
    private const array LIST_COLUMNS = [
        'id', 'site_id', 'title', 'slug', 'excerpt', 'read_minutes', 'hero_image_path',
        'published_at', 'position', 'active', 'featured', 'noindex', 'type',
        'news_category_id', 'source_name', 'source_url', 'updated_at',
    ];

    /**
     * PAGINATED, because this list grows on its own.
     *
     * News is ingested from feeds on a schedule, so the table gains rows
     * whether or not anybody is editing. Unpaginated it was already 120 rows
     * and 111 KB for one site, and at the collector's rate that becomes
     * megabytes within a year — a page nobody can open to fix the problem.
     *
     * `published_count` is in the meta rather than counted in the browser
     * because the browser now only ever sees one page. The banner above the
     * table reports how many entries are LIVE, and the guides section's
     * three-article threshold is read from the same number; counting the
     * current page would make both wrong the moment a second page existed.
     */
    public function index(Request $request, Site $site): JsonResponse
    {
        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $type = $this->type($request);

        $base = fn () => Article::query()->where('site_id', $site->id)->ofType($type);

        $articles = $base()
            ->with('newsCategory')
            ->orderBy('position')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? self::PER_PAGE, self::LIST_COLUMNS);

        return response()->json([
            'data' => ArticleResource::collection($articles->items())->resolve(),
            'meta' => [
                'current_page' => $articles->currentPage(),
                'last_page'    => $articles->lastPage(),
                'total'        => $articles->total(),
                'per_page'     => $articles->perPage(),
                // Both conditions, matching Article::scopeVisible() — the same
                // rule the public API applies, so the banner cannot claim an
                // entry is live that a visitor cannot reach.
                'published_count' => $base()->visible()->count(),
            ],
        ]);
    }

    public function show(Request $request, Site $site, Article $article): JsonResponse
    {
        $this->assertBelongsTo($site, $article, $this->type($request));

        return response()->json(['data' => (new ArticleResource($article))->resolve()]);
    }

    public function store(StoreArticleRequest $request, Site $site): JsonResponse
    {
        // `type` is taken from the request's ?type=, NOT from the payload: which
        // section a post belongs to is decided by the screen it was created on,
        // and accepting it from the body would let a malformed request file a
        // guide into the news feed.
        $article = Article::create([
            ...$request->validated(),
            'site_id' => $site->id,
            'type'    => $this->type($request),
        ]);

        $this->refresh($site, $article);

        return (new ArticleResource($article))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateArticleRequest $request, Site $site, Article $article): JsonResponse
    {
        $this->assertBelongsTo($site, $article, $this->type($request));

        $validated = $request->validated();

        // The slug is in the public URL. Changing it on a PUBLISHED article
        // breaks whatever ranked there, so it is ignored once live — renaming is
        // a new slug plus a redirect, which the Redirects screen exists for.
        if ($article->published_at !== null) {
            unset($validated['slug']);
        }

        $article->update($validated);

        $this->refresh($site, $article);

        return response()->json(['data' => (new ArticleResource($article))->resolve()]);
    }

    public function destroy(Request $request, Site $site, Article $article): JsonResponse
    {
        $this->assertBelongsTo($site, $article, $this->type($request));

        $article->delete();
        $this->refresh($site, $article);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Which kind of article this request is about.
     *
     * Defaults to guides, so every caller that predates news — including the
     * existing guides screen — keeps working untouched. An unrecognised value is
     * a 404 rather than a silent fallback: answering a request for ?type=blog
     * with the guides list would be a lie about what it returned.
     */
    private function type(Request $request): string
    {
        $type = (string) $request->query('type', Article::TYPE_GUIDE);

        abort_unless(in_array($type, Article::TYPES, true), Response::HTTP_NOT_FOUND);

        return $type;
    }

    /**
     * The article must belong to this site AND to the section being edited.
     *
     * Without the type check, the ids are shared across both feeds, so a news id
     * pasted into the guides screen's URL would edit — or delete — a news post
     * from a screen that never showed it.
     */
    private function assertBelongsTo(Site $site, Article $article, string $type): void
    {
        abort_if($article->site_id !== $site->id, Response::HTTP_NOT_FOUND);
        abort_if($article->type !== $type, Response::HTTP_NOT_FOUND);
    }

    /**
     * One site only — guides are never shared, so there is no fan-out.
     *
     * Both tags are sent: the listing changes whenever any article does, and the
     * detail page changes for this one.
     */
    private function refresh(Site $site, Article $article): void
    {
        SiteCache::flushSite($site->id);

        // Section-specific tags: editing a news post must not expire the guides
        // listing, and vice versa.
        $listTag = $article->isNews() ? 'news' : 'articles';
        $itemTag = ($article->isNews() ? 'news:' : 'article:') . $article->slug;

        RevalidateNextJsSites::dispatch([$listTag, $itemTag], [$site->id]);
    }
}
