<?php

declare(strict_types=1);

namespace App\Perfume\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Perfume\Http\Requests\ScentFamilyRequest;
use App\Perfume\Http\Resources\ScentFamilyResource;
use App\Perfume\Models\ScentFamily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScentFamilyController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ScentFamilyResource::collection(ScentFamily::query()->ordered()->get());
    }

    public function store(ScentFamilyRequest $request): JsonResponse
    {
        $row = ScentFamily::create($request->validated());

        return (new ScentFamilyResource($row))->response()->setStatusCode(201);
    }

    public function show(ScentFamily $scentFamily): ScentFamilyResource
    {
        return new ScentFamilyResource($scentFamily);
    }

    public function update(ScentFamilyRequest $request, ScentFamily $scentFamily): ScentFamilyResource
    {
        $scentFamily->update($request->validated());

        return new ScentFamilyResource($scentFamily->fresh());
    }

    public function destroy(ScentFamily $scentFamily): JsonResponse
    {
        $scentFamily->delete();

        return response()->json(null, 204);
    }
}
