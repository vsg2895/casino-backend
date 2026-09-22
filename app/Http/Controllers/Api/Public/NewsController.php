<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Http\Resources\NewsCategoryResource;
use App\Models\Article;
use App\Models\NewsCategory;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * This site's published news.
 *
 * The same rows as the guides feed, filtered to `type = news` — see the
 * migration for why one table serves both. A separate controller rather than a
 * `?type=` parameter on the guides one, because the two differ in the ways that
 * matter to a public endpoint: a different feature flag gates them, they carry
 * different cache tags, and guides enforce a three-article minimum that a news
 * feed must not.
 *
 * Both routes 404 unless the site has `news_enabled`, enforced HERE rather than
 * left to the frontend — the flag is a content decision, and a disabled section
 * must not be readable by anyone who knows the URL.
 */
class NewsController extends Controller
{
    /** How many picks the home page strip will show. */
    private const int FEATURED_LIMIT = 8;

    /** How many entries the feed's "Most popular" rail holds. */
    private const int POPULAR_LIMIT = 5;

    /** @var list<string> The columns a listing row needs — never the body. */
    private const array LIST_COLUMNS = [
        'id', 'site_id', 'type', 'news_category_id', 'title', 'slug', 'excerpt', 'read_minutes',
        'hero_image_path', 'published_at', 'position', 'active', 'featured', 'updated_at',
    ];

    public function index(Request $request): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $this->assertEnabled($site);

        /*
         * `?category=slug` narrows the feed. Part of the cache key, so the
         * filtered and unfiltered views cache apart instead of overwriting each
         * other.
         *
         * An unknown slug yields an EMPTY feed rather than the full one: a
         * reader who followed a stale topic link should see that the topic is
         * gone, not silently get everything.
         */
        $categorySlug = $request->string('category')->trim()->value() ?: null;

        $data = SiteCache::remember(
            $site->id,
            ['news'],
            'news:index:site:' . $site->id . ':cat:' . ($categorySlug ?? 'all'),
            3600,
            function () use ($site, $categorySlug) {
                $base = fn () => Article::query()
                    ->where('site_id', $site->id)
                    ->ofType(Article::TYPE_NEWS)
                    ->visible();

                $feed = $base()->with('newsCategory');

                if ($categorySlug !== null) {
                    $feed->whereHas('newsCategory', fn ($q) => $q->where('slug', $categorySlug)->where('active', true));
                }

                $posts = $feed->inListingOrder()->get(self::LIST_COLUMNS);

                /*
                 * The sidebar list is NOT filtered by the chosen category.
                 * It is the site's standing picks; re-filtering it would leave a
                 * near-empty rail next to a narrow feed, and the whole point of
                 * the rail is to offer a way OUT of a narrow view.
                 */
                $popular = $base()
                    ->featured()
                    ->with('newsCategory')
                    ->inListingOrder()
                    ->limit(self::POPULAR_LIMIT)
                    ->get(self::LIST_COLUMNS);

                // Only categories that actually have a visible post. A topic pill
                // leading to an empty feed is the same broken promise as a menu
                // entry leading to an empty section.
                $categories = NewsCategory::query()
                    ->where('site_id', $site->id)
                    ->active()
                    ->whereHas('articles', fn ($q) => $q->ofType(Article::TYPE_NEWS)->visible())
                    ->withCount(['articles' => fn ($q) => $q->ofType(Article::TYPE_NEWS)->visible()])
                    ->ordered()
                    ->get();

                return [
                    'posts'      => ArticleResource::collection($posts)->resolve(),
                    'popular'    => ArticleResource::collection($popular)->resolve(),
                    'categories' => NewsCategoryResource::collection($categories)->resolve(),
                ];
            },
        );

        return response()->json(['data' => $data]);
    }

    /**
     * The editor's picks, for the home page.
     *
     * Its own endpoint rather than a flag on the feed: the home page needs two
     * compact rows, not the whole section, and it is requested on every visit to the
     * busiest page on the site. A separate cache entry means promoting a post
     * expires the home page's copy without also expiring the news listing's, and
     * the home page never pays to transfer a feed it will throw away.
     */
    public function featured(): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $this->assertEnabled($site);

        $data = SiteCache::remember($site->id, ['news'], 'news:featured:site:' . $site->id, 3600, function () use ($site) {
            $articles = Article::query()
                ->where('site_id', $site->id)
                ->ofType(Article::TYPE_NEWS)
                ->featured()
                ->visible()
                ->inListingOrder()
                // Capped server-side. A home-page strip has room for a few, and
                // an editor who ticks twenty must not be able to push twenty
                // cards into it — the limit is a layout decision, so it belongs
                // where the layout cannot be bypassed.
                ->limit(self::FEATURED_LIMIT)
                ->with('newsCategory')
                ->get(self::LIST_COLUMNS);

            return ArticleResource::collection($articles)->resolve();
        });

        return response()->json(['data' => $data]);
    }

    public function show(string $site, string $slug): JsonResponse
    {
        /** @var Site $resolved */
        $resolved = app('current_site');
        $this->assertEnabled($resolved);

        $data = SiteCache::remember(
            $resolved->id,
            ['news'],
            'news:show:site:' . $resolved->id . ':slug:' . $slug,
            3600,
            function () use ($resolved, $slug) {
                $article = Article::query()
                    ->where('site_id', $resolved->id)
                    ->ofType(Article::TYPE_NEWS)
                    ->where('slug', $slug)
                    ->visible()
                    ->with('newsCategory')
                    ->firstOrFail();

                return (new ArticleResource($article))->resolve();
            },
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Refuse when this site does not publish news.
     *
     * A 404 rather than a 403: a site without news has no such resource, and
     * saying "forbidden" would confirm that the section exists somewhere.
     */
    private function assertEnabled(Site $site): void
    {
        abort_unless($site->news_enabled, Response::HTTP_NOT_FOUND);
    }
}
