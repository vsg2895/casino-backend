<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CasinoWithAttachmentResource;
use App\Http\Resources\CategoryResource;
use App\Models\Casino;
use App\Models\Category;
use App\Models\Country;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * Casinos shown per page within a category.
     *
     * Drives both the home page's category section and the paginated
     * /casinos?category= catalog, so the two always agree on what "a page" is —
     * which is what lets the front end decide whether there is more to show by
     * comparing the total against the rows it received.
     */
    private const PER_PAGE = 6;

    /**
     * Ceiling for a caller-supplied `per_page`.
     *
     * The size is a REQUEST parameter, not a constant, because one surface
     * needed a different page size from the rest: winpalack's home page shows
     * 10 per page while every other listing on every other site still shows
     * PER_PAGE. Changing the constant would have moved all six sites at once.
     *
     * Capped because the value reaches a paginate() and is part of a cache key:
     * an unbounded value is both an unbounded query and unbounded cache churn
     * from a public, unauthenticated endpoint.
     */
    private const MAX_PER_PAGE = 24;

    public function index(): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        // Optional country scope. The category chips are NESTED inside the
        // country filter: with no country they count every casino on the site,
        // and with one they count only that country's — so the number on a chip
        // always matches what clicking it will show.
        $country = $this->countryScope();

        $data = SiteCache::remember(
            $site->id,
            ['categories', 'casinos'],
            'categories:index:site:' . $site->id . ':country:' . ($country ?? 'all'),
            3600,
            function () use ($site, $country) {
            // Country ids this filter means: the chosen country AND the
            // Worldwide wildcard. Resolved once, outside the closure, so the
            // lookup is not repeated for the existence check and the count.
            $countryIds = $country === null ? null : Country::publicFilterIds($country);

            // Only categories that have at least one active casino attached to THIS site,
            // ordered by priority (sort_order), each with a per-site casino count.
            $attachedToSite = function ($query) use ($site, $countryIds): void {
                $query->where('casinos.active', true)
                    ->whereHas('sites', function ($s) use ($site): void {
                        $s->where('sites.id', $site->id)->where('casino_site.active', true);
                    });

                // Same predicate drives BOTH the "which categories exist" filter
                // and the count, so a category can never appear with a count of
                // zero for the selected country.
                if ($countryIds !== null) {
                    self::scopeToCountries($query, $countryIds);
                }
            };

            $categories = Category::query()
                ->whereHas('casinos', $attachedToSite)
                ->withCount(['casinos as casinos_count' => $attachedToSite])
                ->ordered()
                ->get();

                return CategoryResource::collection($categories)->resolve();
            },
        );

        return response()->json(['data' => $data]);
    }

    /**
     * The `?country=` slug, or null when absent or unusable.
     *
     * Null means "every country", which is the default view — the filter starts
     * unset and shows everything, and only narrows once a visitor chooses.
     */
    private function countryScope(): ?string
    {
        $slug = trim((string) request()->query('country', ''));

        return $slug === '' ? null : mb_substr($slug, 0, 120);
    }

    /**
     * Page size for this request: the caller's `per_page`, clamped.
     *
     * Absent or unparseable falls back to PER_PAGE, so every existing caller —
     * five sites and this site's own other listings — keeps the size it has
     * without sending anything new.
     */
    private function perPageScope(): int
    {
        $requested = request()->integer('per_page', 0);

        if ($requested < 1) {
            return self::PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }

    public function show(string $site, string $slug): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $page = max(1, request()->integer('page', 1));
        $perPage = $this->perPageScope();
        // The category list is nested inside the country filter — see index().
        $country = $this->countryScope();

        $data = SiteCache::remember(
            $site->id,
            ['categories', 'casinos'],
            // per_page is part of the key: two callers asking for different
            // page sizes must not be served each other's slice.
            'categories:show:site:' . $site->id . ':slug:' . $slug . ':page:' . $page
                . ':per:' . $perPage . ':country:' . ($country ?? 'all'),
            3600,
            function () use ($site, $slug, $page, $perPage, $country) {
                $category = Category::where('slug', $slug)->firstOrFail();

                $paginator = $category->casinos()
                    ->join('casino_site as pivot', 'casinos.id', '=', 'pivot.casino_id')
                    ->where('pivot.site_id', $site->id)
                    ->where('pivot.active', true)
                    ->where('casinos.active', true)
                    // The same predicate as the chip count above, so the number
                    // on a chip is always the number of rows this query returns.
                    ->when($country !== null, fn ($q) => self::scopeToCountries($q, Country::publicFilterIds((string) $country)))
                    ->orderBy('pivot.position')
                    ->select([
                        'casinos.*',
                        'pivot.affiliate_url',
                        'pivot.position',
                        'pivot.featured',
                    ])
                    ->paginate($perPage, ['*'], 'page', $page);

                // `featuredSpecialOffer` is visibility-gated here for the same
                // reason it is in Public\CasinoController: switching an offer off
                // must remove its card from EVERY public surface, and an
                // unconstrained load would keep serving the hidden offer on this
                // catalog while the casino page correctly dropped it.
                $paginator->getCollection()->load([
                    'categories',
                    'featuredSpecialOffer' => fn ($query) => $query->where('active', true),
                    // The listing card shows where each casino accepts players.
                    ...Casino::publicCountriesEagerLoad(),
                ]);

                return [
                    'category' => (new CategoryResource($category))->resolve(),
                    'casinos'  => CasinoWithAttachmentResource::collection($paginator->getCollection())->resolve(),
                    'meta'     => [
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
     * Narrow a casino query to a set of country ids.
     *
     * whereExists against the pivot rather than whereHas/join, for two separate
     * reasons that both matter here:
     *
     *  - a JOIN would multiply rows for a casino serving several countries, and
     *    the filter now always carries at least two ids (the country and the
     *    Worldwide wildcard), so that duplication would corrupt the ordering and
     *    the pagination totals of the listing;
     *  - it is the same construction {@see \App\Http\Controllers\Api\Public\CountryController}
     *    uses, which is what guarantees a chip count here and a country card
     *    there are computed from one definition of "in this country".
     *
     * An empty id list matches nothing, which is how an unknown `?country=` has
     * always behaved.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Casino>  $query
     * @param  list<int>  $countryIds
     */
    private static function scopeToCountries($query, array $countryIds): void
    {
        $query->whereExists(fn ($q) => $q->from('casino_country')
            ->whereColumn('casino_country.casino_id', 'casinos.id')
            ->whereIn('casino_country.country_id', $countryIds));
    }
}
