<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CasinoWithAttachmentResource;
use App\Http\Resources\ContinentResource;
use App\Http\Resources\CountryResource;
use App\Models\Casino;
use App\Models\Continent;
use App\Models\Country;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Support\Facades\DB;
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
            /*
             * Counts only the casinos attached to THIS site, so a card never
             * advertises another domain's catalogue — and now also counts the
             * Worldwide casinos that the country's own page will list.
             *
             * Written as a correlated subquery rather than withCount() because
             * the condition spans two country ids (this one OR the wildcard) and
             * has to de-duplicate: a casino attached to both must be counted
             * once. COUNT(DISTINCT) over a relation is not something withCount
             * can express.
             *
             * The joins re-state what the Eloquent relation used to imply, so
             * each one matters: `deleted_at IS NULL` because casinos are
             * soft-deleted and a raw builder does not apply that scope, and the
             * casino_site pair because an attachment can be present but inactive.
             */
            $worldwideId = Country::worldwideId();

            $casinoCount = DB::table('casino_country as cc')
                ->join('casinos', 'casinos.id', '=', 'cc.casino_id')
                ->join('casino_site as cs', 'cs.casino_id', '=', 'casinos.id')
                ->where('cs.site_id', $site->id)
                ->where('cs.active', true)
                ->where('casinos.active', true)
                ->whereNull('casinos.deleted_at')
                ->where(function ($q) use ($worldwideId): void {
                    $q->whereColumn('cc.country_id', 'countries.id');

                    if ($worldwideId !== null) {
                        $q->orWhere('cc.country_id', $worldwideId);
                    }
                })
                ->selectRaw('COUNT(DISTINCT casinos.id)');

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
                ->with(['countries' => function ($q) use ($casinoCount): void {
                    $q->where('active', true)
                        ->select('countries.*')
                        ->selectSub($casinoCount, 'casinos_count');
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

                /*
                 * A Worldwide casino belongs on EVERY country's page.
                 *
                 * That is the whole point of the wildcard row: an operator says
                 * "accepts everyone" once instead of attaching the casino to all
                 * 79 countries, and the listing has to honour it or the shortcut
                 * saves nothing.
                 *
                 * whereExists rather than a second join: a casino attached to
                 * BOTH this country and Worldwide matches twice, and a join would
                 * list it twice and corrupt the pagination totals.
                 *
                 * worldwideId() is null on a database where the seeder has not
                 * run, and the query then behaves exactly as it did before.
                 */
                $countryIds = array_values(array_unique(array_filter([
                    $country->id,
                    $country->isWorldwide() ? null : Country::worldwideId(),
                ])));

                $paginator = Casino::query()
                    ->whereExists(fn ($q) => $q->from('casino_country')
                        ->whereColumn('casino_country.casino_id', 'casinos.id')
                        ->whereIn('casino_country.country_id', $countryIds))
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
                    // The listing card shows where each casino accepts players.
                    ...Casino::publicCountriesEagerLoad(),
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
