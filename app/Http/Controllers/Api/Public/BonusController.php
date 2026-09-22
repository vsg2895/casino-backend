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
                            ->with('casino')
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
     * A 404 rather than a 403: a site without the Bonus area has no such
     * resource, and "forbidden" would confirm that it exists somewhere.
     */
    private function assertEnabled(Site $site): void
    {
        abort_unless($site->bonus_enabled, Response::HTTP_NOT_FOUND);
    }
}
