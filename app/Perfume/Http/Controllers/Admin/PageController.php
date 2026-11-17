<?php

declare(strict_types=1);

namespace App\Perfume\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Perfume\Http\Requests\PageRequest;
use App\Perfume\Http\Resources\PageResource;
use App\Perfume\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PageController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PageResource::collection(Page::query()->ordered()->get());
    }

    public function store(PageRequest $request): JsonResponse
    {
        $row = Page::create($request->validated());

        return (new PageResource($row))->response()->setStatusCode(201);
    }

    public function show(Page $page): PageResource
    {
        return new PageResource($page);
    }

    public function update(PageRequest $request, Page $page): PageResource
    {
        $page->update($request->validated());

        return new PageResource($page->fresh());
    }

    public function destroy(Page $page): JsonResponse
    {
        $page->delete();

        return response()->json(null, 204);
    }
}
