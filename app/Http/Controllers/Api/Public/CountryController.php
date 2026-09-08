<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CasinoWithAttachmentResource;
use App\Http\Resources\ContinentResource;
use App\Http\Resources\CountryResource;
use App\Models\Continent;
use App\Models\Country;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;

/**
 * The public countries grid and one country's casino listing.
 *
 * Mirrors {@see \App\Http\Controllers\Api\Public\CategoryController} exactly —
 * same per-site scoping, same SiteCache usage, same resolved-array return so a
 * cached payload can never come back as __PHP_Incomplete_Class.
 *
 * {@see index()} returns the whole tree in ONE response: continents in order,
 * each with its countries. The grid renders headings and cards together, and
 * splitting it into two calls would make the front end stitch the grouping back
 * together for no gain.
 */
class CountryController extends Controller
{
    /** Casinos shown per page within a country. Matches the category catalog. */
    private const PER_PAGE = 6;

    public function index(): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $this->assertEnabled($site);

        $data = SiteCache::remember($site->id, ['countries', 'casinos'], 'countries:index:site:' . $site->id, 3600, function () use ($site) {
            // Counts only the casinos actually attached to THIS site, so a card
            // never advertises another domain's catalogue.
            $attachedToSite = function ($query) use ($site): void {
                $query->where('casinos.active', true)
                    ->whereHas('sites', function ($s) use ($site): void {
                        $s->where('sites.id', $site->id)->where('casino_site.active', true);
                    });
            };

            // EVERY active country is listed, grouped by continent — not only
            // those that already have a casino.
            //
            // This reverses the previous rule ("a card whose Show casinos leads
            // to an empty page is worse than no card"), and it is a deliberate
            // product decision: the hub is a browsable directory, and hiding
            // most of the world until the attachments are filled in left the
            // page blank. The honesty is preserved by the COUNT on each card —
            // a country with none says so — and by the detail page, which states
            // plainly that no casinos are listed yet rather than looking broken.
            //
            // The sitemap still excludes zero-casino countries, so nothing thin
            // is ever submitted for indexing.
            $continents = Continent::query()
                ->whereHas('countries', fn ($q) => $q->where('active', true))
                ->with(['countries' => function ($q) use ($attachedToSite): void {
                    $q->where('active', true)
                        ->withCount(['casinos as casinos_count' => $attachedToSite]);
                }])
                ->ordered()
                ->get();

            return ContinentResource::collection($continents)->resolve();
        });

        return response()->json(['data' => $data]);
    }

    public function show(string $site, string $slug): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $this->assertEnabled($site);
        $page = max(1, request()->integer('page', 1));

        $data = SiteCache::remember(
            $site->id,
            ['countries', 'casinos'],
            'countries:show:site:' . $site->id . ':slug:' . $slug . ':page:' . $page,
            3600,
            function () use ($site, $slug, $page) {
                $country = Country::where('slug', $slug)->where('active', true)->firstOrFail();

                $paginator = $country->casinos()
                    ->join('casino_site as pivot', 'casinos.id', '=', 'pivot.casino_id')
                    ->where('pivot.site_id', $site->id)
                    ->where('pivot.active', true)
                    ->where('casinos.active', true)
                    ->orderBy('pivot.position')
                    ->select([
                        'casinos.*',
                        'pivot.affiliate_url',
                        'pivot.position',
                        'pivot.featured',
                    ])
                    ->paginate(self::PER_PAGE, ['*'], 'page', $page);

                // `featuredSpecialOffer` is visibility-gated for the same reason
                // it is on every other public surface: switching an offer off must
                // remove its card everywhere, and an unconstrained load would keep
                // serving the hidden offer here.
                $paginator->getCollection()->load([
                    'categories',
                    'featuredSpecialOffer' => fn ($query) => $query->where('active', true),
                ]);

                return [
                    'country' => (new CountryResource($country->load('continent')))->resolve(),
                    'casinos' => CasinoWithAttachmentResource::collection($paginator->getCollection())->resolve(),
                    'meta'    => [
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
     * Refuse when this site does not publish the countries filter.
     *
     * The switch is enforced HERE and not only in the front end: a site that has
     * it turned off must not be able to serve the data by someone requesting the
     * URL directly, and a page that was statically generated while the feature
     * was on must stop resolving once it is off.
     *
     * 404 rather than 403, to match how an inactive site is treated by
     * VerifySiteAccess — a feature this site does not have is indistinguishable
     * from one that never existed, and saying which would leak the difference.
     */
    private function assertEnabled(Site $site): void
    {
        abort_unless((bool) $site->countries_enabled, 404);
    }
}
