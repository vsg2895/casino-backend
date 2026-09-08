<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCountryRequest;
use App\Http\Requests\Admin\UpdateCountryRequest;
use App\Http\Resources\ContinentResource;
use App\Http\Resources\CountryResource;
use App\Jobs\InvalidateCasinoCache;
use App\Models\Continent;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Admin CRUD for countries, and a read-only continent list for the picker.
 *
 * Shaped after {@see CategoryController}, including its cache handling:
 * countries are global master data but are RENDERED per site, so an edit must
 * flush the SiteCache of every site publishing a casino in that country and
 * queue a Next.js revalidation. Without it a renamed country keeps showing the
 * old label for up to an hour and the static pages never rebuild.
 */
class CountryController extends Controller
{
    /** Flat list, continent eager-loaded so the admin can group without a second call. */
    public function index(): AnonymousResourceCollection
    {
        return CountryResource::collection(
            Country::query()
                ->with('continent')
                ->withCount('casinos')
                // Continent order first, so the list reads the way the public
                // grid does rather than in id order.
                ->join('continents', 'continents.id', '=', 'countries.continent_id')
                ->orderBy('continents.position')
                ->orderBy('continents.name')
                ->orderBy('countries.position')
                ->orderBy('countries.name')
                ->select('countries.*')
                ->get(),
        );
    }

    /** The continent picker's options. Read-only: continents are fixed reference data. */
    public function continents(): AnonymousResourceCollection
    {
        return ContinentResource::collection(Continent::ordered()->get());
    }

    public function store(StoreCountryRequest $request): CountryResource
    {
        $country = Country::create($request->validated());

        return new CountryResource($country->load('continent'));
    }

    public function update(UpdateCountryRequest $request, Country $country): CountryResource
    {
        $country->update($request->validated());

        $this->dispatchInvalidation($this->siteIdsFor($country));

        return new CountryResource($country->fresh()->load('continent'));
    }

    public function destroy(Country $country): JsonResponse
    {
        // Resolved BEFORE the row goes: the pivot rows cascade with it, and
        // afterwards there is no way left to tell which sites were showing it.
        $siteIds = $this->siteIdsFor($country);

        $country->delete();

        $this->dispatchInvalidation($siteIds);

        return response()->json(null, 204);
    }

    /**
     * Sites that publish at least one casino attached to this country.
     *
     * @return list<int>
     */
    private function siteIdsFor(Country $country): array
    {
        return $country->casinos()
            ->join('casino_site as pivot', 'casinos.id', '=', 'pivot.casino_id')
            ->distinct()
            ->pluck('pivot.site_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** @param  list<int>  $siteIds */
    private function dispatchInvalidation(array $siteIds): void
    {
        $siteIds = array_values(array_unique(array_filter($siteIds)));

        if ($siteIds === []) {
            return;
        }

        InvalidateCasinoCache::dispatch($siteIds, ['countries', 'casinos']);
    }
}
