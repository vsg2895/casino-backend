<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Models\DeletedNewsSlug;
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
    private const int POPULAR_LIMIT = 10;

    /**
     * Posts per page in the feed's GRID, and on the Most Popular page.
     *
     * The grid is what paginates. The two lead cards above it are the newest
     * two of the whole feed and sit outside the page arithmetic, so page 1
     * shows 2 + 20 and every later page shows 20 — see index().
     */
    private const int PER_PAGE = 20;

    /** The lead cards above the grid: the newest two, shown on page 1 only. */
    private const int LEAD_COUNT = 2;

    /** @var list<string> The columns a listing row needs — never the body. */
    private const array LIST_COLUMNS = [
        'id', 'site_id', 'type', 'title', 'slug', 'excerpt', 'read_minutes',
        'hero_image_path', 'published_at', 'active', 'featured', 'to_be_most_popular', 'updated_at',
        // Credited on the detail page. Carried on listing rows too so a card
        // can show its provenance without a second request.
        'source_name', 'source_url',
        /*
         * Needed by the SITEMAP, not by a card.
         *
         * Omitted, `ArticleResource` emitted `false` for every listing row —
         * `(bool) null` on an attribute that was never selected — so a scraped
         * article carrying noindex looked indexable to anything reading the
         * feed. Each site's app/sitemap.ts filters on it, and silently
         * filtered nothing until this column was here.
         */
        'noindex',
    ];

    public function index(Request $request): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $this->assertEnabled($site);

        $page = max(1, $request->integer('page', 1));

        /*
         * `?all=1` returns the WHOLE feed, unpaginated.
         *
         * Two callers need every row and no page: the sitemap, which must list
         * every news URL, and the nav gate, which only asks "is there any news
         * at all". Paginating those would have silently cut the sitemap to one
         * page of entries — a regression that shows up as pages quietly never
         * being discovered, months later. Same escape hatch as `?limit=0` on
         * the Bonus endpoint, and part of the cache key, so the two shapes
         * cache apart.
         */
        $all = $request->boolean('all');

        $data = SiteCache::remember(
            $site->id,
            ['news'],
            'news:index:site:' . $site->id . ($all ? ':all' : ':page:' . $page),
            3600,
            function () use ($site, $page, $all) {
                $base = fn () => Article::query()
                    ->where('site_id', $site->id)
                    ->ofType(Article::TYPE_NEWS)
                    ->visible();

                $ordered = $base()->inListingOrder();

                if ($all) {
                    $posts = $ordered->get(self::LIST_COLUMNS);
                    $meta  = null;
                } else {
                    /*
                     * `posts` stays what it has always been: the feed, in order.
                     * Pagination does not change its meaning, it only decides
                     * how much of it comes back — anything else would quietly
                     * break every caller that reads this key.
                     *
                     * PAGE 1 IS TWO ROWS LONGER THAN THE REST, on purpose. The
                     * page renders its first two posts as big lead cards and the
                     * remainder as a grid, so a plain 20 would leave eighteen
                     * cards under the leads while every later page showed
                     * twenty. 22 on page 1 keeps the GRID at twenty everywhere,
                     * which is the number a reader actually sees.
                     */
                    $firstPageSize = self::LEAD_COUNT + self::PER_PAGE;
                    $offset = $page === 1 ? 0 : $firstPageSize + ($page - 2) * self::PER_PAGE;
                    $take = $page === 1 ? $firstPageSize : self::PER_PAGE;

                    $total = (clone $ordered)->toBase()->getCountForPagination();

                    $posts = (clone $ordered)->skip($offset)->take($take)->get(self::LIST_COLUMNS);

                    $meta = [
                        'current_page' => $page,
                        // Page 1 swallows the leads plus a full grid; every page
                        // after it is one grid.
                        'last_page'    => $total <= $firstPageSize
                            ? 1
                            : 1 + (int) ceil(($total - $firstPageSize) / self::PER_PAGE),
                        'per_page'     => self::PER_PAGE,
                        'total'        => $total,
                        // How many the FIRST page holds, so the client never has
                        // to re-derive the lead offset from two constants it does
                        // not own.
                        'first_page_size' => $firstPageSize,
                    ];
                }

                /*
                 * The sidebar list: the site's standing picks. Driven by `to_be_most_popular`, its OWN flag — it used to
                 * reuse `featured`, which meant promoting a post to the home
                 * page silently put it in this rail too, and the two surfaces
                 * could never be curated apart.
                 *
                 * Ordered by WHEN EACH POST WAS PICKED, newest pick first, so
                 * the feed's editorial ordering never reaches in and reorders
                 * it — and neither does publication date, which is a fact about
                 * the story rather than about the pick. Promoting a post today
                 * puts it at the top of the rail today, whatever it is dated.
                 */
                $popular = $base()
                    ->toBeMostPopular()
                    ->mostPopularFirst()
                    ->limit(self::POPULAR_LIMIT)
                    ->get(self::LIST_COLUMNS);

                return [
                    'posts'   => ArticleResource::collection($posts)->resolve(),
                    'popular' => ArticleResource::collection($popular)->resolve(),
                    'meta'    => $meta,
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
                ->get(self::LIST_COLUMNS);

            return ArticleResource::collection($articles)->resolve();
        });

        return response()->json(['data' => $data]);
    }

    /**
     * The Most Popular rail's own page: every pick, paginated.
     *
     * The rail on the feed holds ten, which is a sidebar's worth and not a
     * listing — "See more" needs somewhere to go that holds all of them.
     *
     * Same selection and same order as the rail (`to_be_most_popular`, newest
     * PICK first), so the page is the rail continued rather than a second
     * opinion about what is popular. Declared before `news/{slug}` in the
     * routes file, or "popular" is swallowed as a post slug.
     */
    public function popular(Request $request): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $this->assertEnabled($site);

        $page = max(1, $request->integer('page', 1));

        $data = SiteCache::remember(
            $site->id,
            ['news'],
            'news:popular:site:' . $site->id . ':page:' . $page,
            3600,
            function () use ($site, $page) {
                $paginator = Article::query()
                    ->where('site_id', $site->id)
                    ->ofType(Article::TYPE_NEWS)
                    ->visible()
                    ->toBeMostPopular()
                    ->mostPopularFirst()
                    ->paginate(self::PER_PAGE, self::LIST_COLUMNS, 'page', $page);

                return [
                    'posts' => ArticleResource::collection($paginator->getCollection())->resolve(),
                    'meta'  => [
                        'current_page' => $paginator->currentPage(),
                        'last_page'    => $paginator->lastPage(),
                        'per_page'     => $paginator->perPage(),
                        'total'        => $paginator->total(),
                    ],
                ];
            },
        );

        return response()->json(['data' => $data]);
    }

    /**
     * The slugs of this site's DELETED news posts, for the site's proxy to
     * answer with 410 Gone.
     *
     * A deleted post's URL would otherwise be a 404, which search engines keep
     * re-crawling for a while before they drop it; 410 says the removal was
     * deliberate. Read from the tombstones hard deletes leave
     * (DeletedNewsSlug), plus any row still only soft-deleted from before
     * deletes were permanent. Only posts that were ever public — a draft that
     * never went live has nothing indexed to clear — and only those deleted
     * within `news.gone_days`, so the list stays small.
     *
     * A slug a live post holds is never listed, whatever the tombstones say:
     * answering a live article 410 would deindex it.
     *
     * Plain slugs, not paths: the proxy owns the `/news/` prefix.
     */
    public function gone(): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $this->assertEnabled($site);

        $data = SiteCache::remember($site->id, ['news'], 'news:gone:site:' . $site->id, 3600, function () use ($site): array {
            $since = now()->subDays(max(1, (int) config('news.gone_days', 365)));

            $tombstones = DeletedNewsSlug::ready()
                ? DeletedNewsSlug::query()
                    ->where('site_id', $site->id)
                    ->where('was_public', true)
                    ->where('deleted_at', '>=', $since)
                    ->pluck('slug')
                    ->all()
                : [];

            $trashed = Article::onlyTrashed()
                ->where('site_id', $site->id)
                ->ofType(Article::TYPE_NEWS)
                ->whereNotNull('published_at')
                ->whereNotNull('body')
                ->where('body', '!=', '')
                ->where('deleted_at', '>=', $since)
                ->pluck('slug')
                ->all();

            $slugs = array_values(array_unique(array_map('strval', [...$tombstones, ...$trashed])));

            if ($slugs === []) {
                return [];
            }

            $live = Article::query()
                ->where('site_id', $site->id)
                ->whereIn('slug', $slugs)
                ->pluck('slug')
                ->map(static fn ($slug): string => (string) $slug)
                ->all();

            $gone = array_values(array_diff($slugs, $live));
            sort($gone);

            return $gone;
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
                    ->with(['author' => fn ($q) => $q->visible(), 'reviewer' => fn ($q) => $q->visible()])
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
