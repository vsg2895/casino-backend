<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCasinoRequest;
use App\Http\Requests\Admin\UpdateCasinoRequest;
use App\Http\Resources\{CasinoCollection, CasinoResource};
use App\Models\Casino;
use Illuminate\Http\JsonResponse;

class CasinoController extends Controller
{
    public function index(): CasinoCollection
    {
        return new CasinoCollection(
            Casino::with('categories')->latest()->paginate(15)
        );
    }

    public function store(StoreCasinoRequest $request): CasinoResource
    {
        $data = $request->validated();
        $categoryIds = $data['category_ids'] ?? null;
        unset($data['category_ids']);

        $casino = Casino::create($data);

        if ($categoryIds !== null) {
            $casino->categories()->sync($categoryIds);
        }

        return new CasinoResource($casino->load(['categories', 'sites', 'specialOffers']));
    }

    public function show(Casino $casino): CasinoResource
    {
        return new CasinoResource($casino->load(['categories', 'sites', 'specialOffers']));
    }

    public function update(UpdateCasinoRequest $request, Casino $casino): CasinoResource
    {
        $data = $request->validated();
        $categoryIds = $data['category_ids'] ?? null;
        unset($data['category_ids']);

        $casino->update($data);

        if ($categoryIds !== null) {
            $casino->categories()->sync($categoryIds);
        }

        // CasinoObserver::saved() handles cache invalidation and revalidation.

        return new CasinoResource($casino->fresh(['categories', 'sites', 'specialOffers']));
    }

    public function destroy(Casino $casino): JsonResponse
    {
        $casino->delete();

        // CasinoObserver::deleted() handles cache invalidation and revalidation.

        return response()->json(null, 204);
    }
}
