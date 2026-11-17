<?php

declare(strict_types=1);

namespace App\Perfume\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Perfume\Http\Requests\PerfumerRequest;
use App\Perfume\Http\Resources\PerfumerResource;
use App\Perfume\Models\Perfumer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PerfumerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PerfumerResource::collection(Perfumer::query()->withCount('products')->ordered()->get());
    }

    public function store(PerfumerRequest $request): JsonResponse
    {
        $row = Perfumer::create($request->validated());

        return (new PerfumerResource($row))->response()->setStatusCode(201);
    }

    public function show(Perfumer $perfumer): PerfumerResource
    {
        return new PerfumerResource($perfumer);
    }

    public function update(PerfumerRequest $request, Perfumer $perfumer): PerfumerResource
    {
        $perfumer->update($request->validated());

        return new PerfumerResource($perfumer->fresh());
    }

    public function destroy(Perfumer $perfumer): JsonResponse
    {
        $perfumer->delete();

        return response()->json(null, 204);
    }
}
