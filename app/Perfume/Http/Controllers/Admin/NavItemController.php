<?php

declare(strict_types=1);

namespace App\Perfume\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Perfume\Http\Requests\NavItemRequest;
use App\Perfume\Http\Resources\NavItemResource;
use App\Perfume\Models\NavItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NavItemController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return NavItemResource::collection(NavItem::query()->ordered()->get());
    }

    public function store(NavItemRequest $request): JsonResponse
    {
        $row = NavItem::create($request->validated());

        return (new NavItemResource($row))->response()->setStatusCode(201);
    }

    public function show(NavItem $navItem): NavItemResource
    {
        return new NavItemResource($navItem);
    }

    public function update(NavItemRequest $request, NavItem $navItem): NavItemResource
    {
        $navItem->update($request->validated());

        return new NavItemResource($navItem->fresh());
    }

    public function destroy(NavItem $navItem): JsonResponse
    {
        $navItem->delete();

        return response()->json(null, 204);
    }
}
