<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCasinoRequest;
use App\Http\Requests\Admin\UpdateCasinoRequest;
use App\Http\Resources\{CasinoCollection, CasinoResource};
use App\Models\Casino;
use App\Services\Search\SearchIndexer;
use Illuminate\Http\JsonResponse;

class CasinoController extends Controller
{
    public function __construct(private readonly SearchIndexer $indexer) {}

    public function index(): CasinoCollection
    {
        return new CasinoCollection(
            Casino::with('categories')->latest()->paginate(15)
        );
    }

    /**
     * Total casinos, as a dedicated COUNT.
     *
     * Kept off the listing request on purpose: the listing eager-loads
     * categories and orders by created_at, and neither has any bearing on the
     * total. This is a bare COUNT against the primary table (soft-deleted rows
     * excluded by the model's global scope), so it stays cheap as the catalogue
     * grows.
     */
    public function count(): JsonResponse
    {
        return response()->json(['total' => Casino::query()->count()]);
    }

    public function store(StoreCasinoRequest $request): CasinoResource
    {
        $data = $request->validated();
        $categoryIds = $data['category_ids'] ?? null;
        $countryIds = $data['country_ids'] ?? null;
        unset($data['category_ids'], $data['country_ids']);

        $casino = Casino::create($data);

        if ($categoryIds !== null) {
            $casino->categories()->sync($categoryIds);
        }

        if ($countryIds !== null) {
            $casino->countries()->sync($countryIds);
        }

        // The categories pivot is synced AFTER the model save, so the observer's
        // `saved` hook fired before these rows existed and could not see them.
        // A category's search visibility is derived from its casinos, so this
        // call is what adds or removes the whole category from a site.
        $this->indexer->syncCasino($casino);

        return new CasinoResource($casino->load(['categories', 'countries', 'sites', 'specialOffers']));
    }

    public function show(Casino $casino): CasinoResource
    {
        return new CasinoResource($casino->load(['categories', 'countries', 'sites', 'specialOffers']));
    }

    public function update(UpdateCasinoRequest $request, Casino $casino): CasinoResource
    {
        $data = $request->validated();
        $categoryIds = $data['category_ids'] ?? null;
        $countryIds = $data['country_ids'] ?? null;
        unset($data['category_ids'], $data['country_ids']);

        $casino->update($data);

        if ($categoryIds !== null) {
            $casino->categories()->sync($categoryIds);
        }

        if ($countryIds !== null) {
            $casino->countries()->sync($countryIds);
        }

        // CasinoObserver::saved() handles cache invalidation and revalidation.

        // The categories pivot is synced AFTER the model save, so the observer's
        // `saved` hook fired before these rows existed and could not see them.
        // A category's search visibility is derived from its casinos, so this
        // call is what adds or removes the whole category from a site.
        $this->indexer->syncCasino($casino);

        return new CasinoResource($casino->fresh(['categories', 'countries', 'sites', 'specialOffers']));
    }

    public function destroy(Casino $casino): JsonResponse
    {
        $casino->delete();

        // CasinoObserver::deleted() handles cache invalidation and revalidation.

        return response()->json(null, 204);
    }
}
