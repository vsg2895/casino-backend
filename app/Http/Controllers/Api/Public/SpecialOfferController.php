<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\SpecialOfferResource;
use App\Models\Site;
use App\Models\SpecialOffer;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpecialOfferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        // Optional filters: ?category={slug} (offers whose casino is in that
        // category) and ?limit={n}. Both feed the cache key so each variant is
        // cached independently and busted together on any offer/casino change.
        $category = $request->string('category')->trim()->value() ?: null;
        $limit = $request->integer('limit');
        $limit = $limit > 0 ? min($limit, 50) : null;

        $cacheKey = 'special-offers:index:site:' . $site->id
            . ':cat:' . ($category ?? 'all')
            . ':limit:' . ($limit ?? 'all');

        $data = SiteCache::remember($site->id, ['special-offers'], $cacheKey, 3600, function () use ($site, $category, $limit) {
            $query = $this->baseQuery($site)->orderBy('special_offers.sort_order');

            if ($category !== null) {
                $query->whereHas('casino.categories', function ($q) use ($category): void {
                    $q->where('categories.slug', $category);
                });
            }

            if ($limit !== null) {
                $query->limit($limit);
            }

            return SpecialOfferResource::collection($query->get())->resolve();
        });

        return response()->json(['data' => $data]);
    }

    public function show(string $site, string $slug): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $data = SiteCache::remember($site->id, ['special-offers'], 'special-offers:show:site:' . $site->id . ':slug:' . $slug, 3600, function () use ($site, $slug) {
            $offer = $this->baseQuery($site)->where('special_offers.slug', $slug)->firstOrFail();

            return (new SpecialOfferResource($offer))->resolve();
        });

        return response()->json(['data' => $data]);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<SpecialOffer> */
    private function baseQuery(Site $site)
    {
        return SpecialOffer::query()
            ->with('casino')
            ->where('special_offers.active', true)
            ->whereHas('casino.sites', function ($q) use ($site): void {
                $q->where('sites.id', $site->id)->where('casino_site.active', true);
            });
    }
}
