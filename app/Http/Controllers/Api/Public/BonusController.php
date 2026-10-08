<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\SpecialOfferResource;
use App\Models\BonusCategory;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Bonus area: the categories, each with the offers filed under it.
 *
 * ONE response drives two surfaces — the Bonus dropdown in the header and the
 * stack of sections on the home page. They are generated from the same payload
 * on purpose: a menu entry can never point at a section that is not there,
 * because both come from the same list.
 *
 * A category with NO visible offers on this site is dropped from the response
 * entirely. That is the rule that keeps the two surfaces honest: no empty
 * section, and no menu entry leading to one. A site that lists three casinos
 * does not advertise a "No Deposit" heading with nothing under it.
 *
 * 404s unless the site has `bonus_enabled`, enforced here rather than left to
 * the frontend.
 */
class BonusController extends Controller
{
    /**
     * Offers rendered per section on the home page.
     *
     * Capped server-side: the home page has two rows of four per category,
     * and an operator who files forty offers under one heading must not be able
     * to push forty cards into it. "See all" leads to the full listing.
     */
    private const int OFFERS_PER_CATEGORY = 8;

    /**
     * Offers per page on a single category's own page (`/bonus/{slug}`).
     *
     * A page size, not a cap: everything filed under the heading is reachable,
     * eight rows at a time. Deliberately its own constant — it answers "how big
     * is a page of this listing", while OFFERS_PER_CATEGORY answers "how much of
     * a category does the home strip preview", and the two moving together would
     * be a coincidence rather than a rule.
     */
    private const int OFFERS_PER_PAGE = 8;

    public function index(Request $request): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $this->assertEnabled($site);

        /*
         * `?limit=0` lifts the per-section cap.
         *
         * The home page wants a strip; the full offers listing wants everything
         * under each heading. One endpoint serves both because the SHAPE is
         * identical — only the depth differs — and a second endpoint would be
         * the same query with one number changed.
         *
         * The limit is part of the cache key, so the two variants cannot
         * overwrite each other's cached copy.
         */
        $limit = $request->has('limit')
            ? max(0, min(100, $request->integer('limit')))
            : self::OFFERS_PER_CATEGORY;

        $data = SiteCache::remember(
            $site->id,
            ['bonus', 'special-offers'],
            'bonus:index:site:' . $site->id . ':limit:' . $limit,
            3600,
            function () use ($site, $limit) {
                $categories = BonusCategory::query()
                    ->active()
                    ->ordered()
                    ->with(['specialOffers' => function ($query) use ($site, $limit): void {
                        $query->where('special_offers.active', true)
                            ->claimable()
                            // Scoped to THIS site through the offer's casino, the
                            // same way the offers listing does it — an offer whose
                            // casino is not on this domain is not this domain's to
                            // advertise.
                            ->whereHas('casino.sites', function ($q) use ($site): void {
                                $q->where('sites.id', $site->id)->where('casino_site.active', true);
                            })
                            ->whereHas('casino', fn ($q) => $q->where('casinos.active', true))
                            ->with(['casino', 'bonusCategory'])
                            ->orderBy('sort_order');

                        // 0 means "no cap" — the full listing page.
                        if ($limit > 0) {
                            $query->limit($limit);
                        }
                    }])
                    ->get();

                return $categories
                    // Empty sections are not rendered, so they are not returned.
                    ->filter(fn (BonusCategory $category) => $category->specialOffers->isNotEmpty())
                    ->map(fn (BonusCategory $category) => [
                        'id'          => $category->id,
                        'name'        => $category->name,
                        'slug'        => $category->slug,
                        'description' => $category->description,
                        'position'    => $category->position,
                        'offers'      => SpecialOfferResource::collection($category->specialOffers)->resolve(),
                    ])
                    ->values()
                    ->all();
            },
        );

        return response()->json(['data' => $data]);
    }

    /**
     * One category, with its offers paginated.
     *
     * The page behind each entry in the Bonus menu. Before this, those entries
     * were anchors into the home page (`/#bonus-<slug>`), which meant a category
     * had no address of its own: it could not be linked to, indexed, or read
     * past the handful of cards the home strip previews.
     *
     * Selection is the SAME predicate as index() — active, claimable, filed
     * under this category, owned by a casino attached to and active on this site
     * — so a card that appears in the home strip appears here, and nothing
     * appears here that the strip would have refused to show. Only the depth
     * differs: the strip previews, this paginates through everything.
     *
     * An inactive category is a 404, not an empty page. It is not published, so
     * it has no address, and an empty listing would read as "this exists and
     * holds nothing".
     */
    public function show(string $site, string $slug): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $this->assertEnabled($site);

        $page = max(1, request()->integer('page', 1));

        $data = SiteCache::remember(
            $site->id,
            ['bonus', 'special-offers'],
            'bonus:show:site:' . $site->id . ':slug:' . $slug . ':page:' . $page,
            3600,
            function () use ($site, $slug, $page) {
                $category = BonusCategory::query()->active()->where('slug', $slug)->firstOrFail();

                $paginator = $category->specialOffers()
                    ->where('special_offers.active', true)
                    ->claimable()
                    ->whereHas('casino.sites', function ($q) use ($site): void {
                        $q->where('sites.id', $site->id)->where('casino_site.active', true);
                    })
                    ->whereHas('casino', fn ($q) => $q->where('casinos.active', true))
                    ->with(['casino', 'bonusCategory'])
                    ->orderBy('special_offers.sort_order')
                    ->paginate(self::OFFERS_PER_PAGE, ['*'], 'page', $page);

                return [
                    'category' => [
                        'id'          => $category->id,
                        'name'        => $category->name,
                        'slug'        => $category->slug,
                        'description' => $category->description,
                        'position'    => $category->position,
                    ],
                    'offers' => SpecialOfferResource::collection($paginator->getCollection())->resolve(),
                    'meta'   => [
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
     * A 404 rather than a 403: a site without the Bonus area has no such
     * resource, and "forbidden" would confirm that it exists somewhere.
     */
    private function assertEnabled(Site $site): void
    {
        abort_unless($site->bonus_enabled, Response::HTTP_NOT_FOUND);
    }
}
