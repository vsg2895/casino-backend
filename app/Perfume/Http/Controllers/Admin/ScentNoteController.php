<?php

declare(strict_types=1);

namespace App\Perfume\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Perfume\Http\Requests\ScentNoteRequest;
use App\Perfume\Http\Resources\ScentNoteResource;
use App\Perfume\Models\ScentNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScentNoteController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ScentNoteResource::collection(ScentNote::query()->withCount('products')->ordered()->get());
    }

    public function store(ScentNoteRequest $request): JsonResponse
    {
        $row = ScentNote::create($request->validated());

        return (new ScentNoteResource($row))->response()->setStatusCode(201);
    }

    public function show(ScentNote $scentNote): ScentNoteResource
    {
        return new ScentNoteResource($scentNote);
    }

    public function update(ScentNoteRequest $request, ScentNote $scentNote): ScentNoteResource
    {
        $scentNote->update($request->validated());

        return new ScentNoteResource($scentNote->fresh());
    }

    public function destroy(ScentNote $scentNote): JsonResponse
    {
        $scentNote->delete();

        return response()->json(null, 204);
    }
}
