<?php

declare(strict_types=1);

namespace App\Perfume\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Perfume\Http\Requests\StoreRequest;
use App\Perfume\Http\Resources\StoreResource;
use App\Perfume\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StoreController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return StoreResource::collection(Store::query()->ordered()->get());
    }

    public function store(StoreRequest $request): JsonResponse
    {
        $row = Store::create($request->validated());

        return (new StoreResource($row))->response()->setStatusCode(201);
    }

    public function show(Store $store): StoreResource
    {
        return new StoreResource($store);
    }

    public function update(StoreRequest $request, Store $store): StoreResource
    {
        $store->update($request->validated());

        return new StoreResource($store->fresh());
    }

    public function destroy(Store $store): JsonResponse
    {
        $store->delete();

        return response()->json(null, 204);
    }
}
