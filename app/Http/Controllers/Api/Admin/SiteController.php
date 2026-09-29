<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSiteRequest;
use App\Http\Requests\Admin\UpdateSiteRequest;
use App\Http\Resources\SiteRegistrationResource;
use App\Http\Resources\SiteResource;
use App\Jobs\InvalidateCasinoCache;
use App\Models\Site;
use App\Services\CmsPageService;
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
        return SiteResource::collection(Site::latest()->get());
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
        return new SiteResource($site);
    }

    public function update(UpdateSiteRequest $request, Site $site): SiteResource
    {
        $wasCountries = (bool) $site->countries_enabled;
        $wasReviews = (bool) $site->reviews_enabled;

        $site->update($request->validated());

        // Flipping the countries switch changes what this site serves, so the
        // cached payload and the statically generated pages have to go — in BOTH
        // directions. Off without this leaves the grid served from cache for up
        // to an hour after it was withdrawn; on without it leaves the new pages
        // 404-ing until something else happens to invalidate them.
        $fresh = $site->fresh();

        if ($wasCountries !== (bool) $fresh->countries_enabled) {
            InvalidateCasinoCache::dispatch([$site->id], ['countries', 'casinos']);
        }

        if ($wasReviews !== (bool) $fresh->reviews_enabled) {
            InvalidateCasinoCache::dispatch([$site->id], ['reviews', 'casinos']);
        }

        return new SiteResource($site);
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
