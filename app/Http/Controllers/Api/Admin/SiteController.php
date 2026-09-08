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

    public function index(): AnonymousResourceCollection
    {
        return SiteResource::collection(Site::latest()->paginate(15));
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
