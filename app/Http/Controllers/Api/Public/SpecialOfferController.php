<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\SpecialOfferResource;
use App\Models\Site;
use App\Models\SpecialOffer;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;

class SpecialOfferController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $data = SiteCache::remember($site->id, ['special-offers'], 'special-offers:index:site:' . $site->id, 3600, function () use ($site) {
            $offers = $this->baseQuery($site)->orderBy('special_offers.sort_order')->get();

            return SpecialOfferResource::collection($offers)->resolve();
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
