<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSiteRequest;
use App\Http\Requests\Admin\UpdateSiteRequest;
use App\Http\Resources\SiteRegistrationResource;
use App\Http\Resources\SiteResource;
use App\Jobs\InvalidateCasinoCache;
use App\Jobs\RevalidateNextJsSites;
use App\Models\CmsPage;
use App\Models\Site;
use App\Services\CmsPageService;
use App\Support\LegalIdentity;
use App\Support\SchemaGuard;
use App\Support\SiteCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;

class SiteController extends Controller
{
    public function __construct(private readonly CmsPageService $cmsPages) {}

    /**
     * EVERY site, unpaginated — deliberately.
     *
     * This endpoint is not only the Sites screen: it is the site picker behind
     * roughly thirty admin controls (attachments, templates, schedules, page
     * and article filters). Paginating it at 15 meant a sixteenth site would
     * silently vanish from all of them at once — no error, no empty state, just
     * a domain that could no longer be chosen anywhere in the admin.
     *
     * A site is a registered domain and the set is bounded by how many the
     * operator runs — six today, and adding one is a deliberate act, not
     * user-generated growth. Sending them all is a handful of rows and removes
     * a whole class of "where did that site go" bug.
     *
     * The response keeps its `data` envelope, so every existing caller reads it
     * exactly as before; only the pagination meta nobody consumed is gone.
     */
    public function index(): AnonymousResourceCollection
    {
        return SiteResource::collection(self::withNoindexCountries(Site::latest())->get());
    }

    public function store(StoreSiteRequest $request): SiteRegistrationResource
    {
        $plain = Site::generateApiKey();

        $site = Site::create([
            ...$request->validated(),
            'api_key' => Hash::make($plain),
        ]);

        // Every new domain ships with the full set of brand-aware legal pages.
        $this->cmsPages->seedDefaultsForSite($site);

        return new SiteRegistrationResource($site, $plain);
    }

    public function show(Site $site): SiteResource
    {
        return new SiteResource(self::loadNoindexCountries($site));
    }

    /**
     * The edit dialog takes its site from the listing, so every response that
     * can open it carries the hidden-countries set. Skipped until the table's
     * migration has run, so deploying the code first breaks nothing.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    private static function withNoindexCountries(Builder $query): Builder
    {
        return SchemaGuard::hasTable('country_site_noindex')
            ? $query->with(['noindexCountries' => fn ($q) => $q->select('countries.id')])
            : $query;
    }

    private static function loadNoindexCountries(Site $site): Site
    {
        return SchemaGuard::hasTable('country_site_noindex')
            ? $site->load(['noindexCountries' => fn ($q) => $q->select('countries.id')])
            : $site;
    }

    /** Feature flags that decide which lines of the legal pages show. */
    private const array PAGE_FEATURE_FIELDS = ['reviews_enabled', 'accounts_enabled', 'forum_enabled'];

    /** Site columns the editorial endpoint publishes. */
    private const array EDITORIAL_FIELDS = [
        'byline_enabled', 'author_name', 'author_role', 'author_bio', 'author_avatar_path', 'methodology_page_slug',
    ];

    public function update(UpdateSiteRequest $request, Site $site): SiteResource
    {
        $wasCountries = (bool) $site->countries_enabled;
        $wasReviews = (bool) $site->reviews_enabled;

        // Fields whose migration has not run yet are dropped rather than
        // failing the whole save (SchemaGuard).
        $site->update(SchemaGuard::onlyExistingColumns('sites', $request->validated()));

        // Flipping the countries switch changes what this site serves, so the
        // cached payload and the statically generated pages have to go — in BOTH
        // directions. Off without this leaves the grid served from cache for up
        // to an hour after it was withdrawn; on without it leaves the new pages
        // 404-ing until something else happens to invalidate them.
        $fresh = $site->fresh();

        if ($wasCountries !== (bool) $fresh->countries_enabled) {
            InvalidateCasinoCache::dispatch([$site->id], ['countries', 'casinos']);
        }

        // The country pages' `noindex` and the sitemap read this set, so a
        // change has to reach both — `countries` is on every country fetch.
        if ($request->has('noindex_country_ids') && SchemaGuard::hasTable('country_site_noindex')) {
            $changes = $site->noindexCountries()->sync($request->validated('noindex_country_ids'));

            if (array_filter($changes) !== []) {
                InvalidateCasinoCache::dispatch([$site->id], ['countries']);
            }
        }

        if ($wasReviews !== (bool) $fresh->reviews_enabled) {
            InvalidateCasinoCache::dispatch([$site->id], ['reviews', 'casinos']);
        }

        // Identity and byline settings are rendered into every legal page, the
        // footer and the JSON-LD; the feature switches decide which lines of
        // the legal pages show (LegalIdentity::pageValuesFor). Without this
        // they waited out the hour-long cache, so a corrected company name
        // stayed wrong on the live site.
        //
        // The pages' own `page:<slug>` tags go too: a Next.js page is cached
        // under its slug, not under `editorial`, so revalidating `editorial`
        // alone refreshed the footer while the legal pages kept the old text.
        if ($site->wasChanged([...LegalIdentity::TOKENS, ...self::EDITORIAL_FIELDS, ...self::PAGE_FEATURE_FIELDS])) {
            SiteCache::flushSite($site->id);

            if ($fresh->active) {
                $pageTags = CmsPage::query()->where('site_id', $site->id)->pluck('slug')
                    ->map(static fn (string $slug): string => 'page:' . $slug)->all();

                RevalidateNextJsSites::dispatch(['editorial', ...$pageTags], [$site->id]);
            }
        }

        return new SiteResource(self::loadNoindexCountries($site));
    }

    public function destroy(Site $site): JsonResponse
    {
        $site->delete();

        return response()->json(null, 204);
    }

    public function rotateKey(Site $site): SiteRegistrationResource
    {
        $plain = $site->rotateApiKey();

        return new SiteRegistrationResource($site, $plain);
    }
}
