<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CasinoWithAttachmentResource;
use App\Models\Casino;
use App\Models\FacetConfig;
use App\Models\Site;
use App\Services\CasinoFacetService;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CasinoController extends Controller
{
    /**
     * Relations rendered on the public site for every casino.
     *
     * The special-offer relations are constrained to VISIBLE offers here rather
     * than on the relation itself, because the admin loads the same relations
     * and must keep seeing hidden offers in order to edit them. Switching an
     * offer's visibility off therefore removes its card from the casino page
     * (and from a casino's featured slot) while leaving the offer intact and
     * still reachable by direct URL.
     *
     * @return array<string, \Closure|string>
     */
    private static function relations(): array
    {
        // Visible AND not past its expiry date. Both gates, because an
        // offer embedded on a casino page is presented as claimable exactly the
        // way one in the offers listing is.
        $visibleOnly = static fn ($query) => $query->where('active', true)->claimable();

        return [
            'categories',
            // Active countries only, in the same order the /countries hub uses.
            // An inactive country must never be linked from here: its own detail
            // route resolves with ->where('active', true)->firstOrFail(), so a
            // link to one would be a 404 pointed at from every casino that
            // happens to be attached to it.
            'countries' => static fn ($query) => $query
                ->where('active', true)
                ->orderBy('position')
                ->orderBy('name'),
            // One-to-one, so no filter closure — an absent profile simply
            // leaves the relation null and the resource omits the key.
            'detail',
            'featuredSpecialOffer' => $visibleOnly,
            'specialOffers'        => $visibleOnly,
        ];
    }

    public function index(Request $request, CasinoFacetService $facets): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        // Optional facet filters. NO parameters means byte-identical behaviour
        // to before this feature existed, which is what keeps the other five
        // sites unaffected.
        $selected = [];

        foreach (FacetConfig::FACETS as $facet) {
            $value = trim((string) $request->query($facet, ''));

            if ($value !== '') {
                // Bounded so a hostile query string cannot become a huge LIKE.
                $selected[$facet] = mb_substr($value, 0, 80);
            }
        }

        // Each filter combination is cached separately and all of them are
        // busted together by the `casinos` tag on any casino change.
        $cacheKey = 'casinos:index:site:' . $site->id
            . ':f:' . ($selected === [] ? 'none' : md5(json_encode($selected)));

        $data = SiteCache::remember($site->id, ['casinos'], $cacheKey, 3600, function () use ($site, $selected, $facets) {
            $query = $this->baseQuery($site);

            if ($selected !== []) {
                $facets->apply($query, $selected);
            }

            $casinos = $query->orderBy('pivot.position')->get()->load(self::relations());

            return CasinoWithAttachmentResource::collection($casinos)->resolve();
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'    => count($data),
                'selected' => (object) $selected,
            ],
        ]);
    }

    /**
     * The filters this site should render, with their available values.
     *
     * A separate endpoint from the listing: the facet set changes only when
     * content changes, so the front end fetches it once for the page rather than
     * re-receiving the whole set inside every filtered response.
     */
    public function facets(CasinoFacetService $facets): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $data = SiteCache::remember($site->id, ['casinos'], 'casinos:facets:site:' . $site->id, 3600,
            fn () => $facets->available($site));

        return response()->json(['data' => $data]);
    }

    public function show(string $site, string $slug): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $data = SiteCache::remember($site->id, ['casinos'], 'casinos:show:site:' . $site->id . ':slug:' . $slug, 3600, function () use ($site, $slug) {
            $casino = $this->baseQuery($site)->where('casinos.slug', $slug)->firstOrFail()->load(self::relations());

            return (new CasinoWithAttachmentResource($casino))->resolve();
        });

        return response()->json(['data' => $data]);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Casino> */
    private function baseQuery(Site $site)
    {
        return Casino::query()
            ->join('casino_site as pivot', 'casinos.id', '=', 'pivot.casino_id')
            ->where('pivot.site_id', $site->id)
            ->where('pivot.active', true)
            ->where('casinos.active', true)
            ->whereNull('casinos.deleted_at')
            ->select([
                'casinos.*',
                'pivot.affiliate_url',
                'pivot.position',
                'pivot.featured',
            ]);
    }
}
