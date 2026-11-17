<?php

declare(strict_types=1);

namespace App\Perfume\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Perfume\Http\Requests\CategoryRequest;
use App\Perfume\Http\Resources\CategoryResource;
use App\Perfume\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::query()->withCount('products')->ordered()->get());
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $row = Category::create($request->validated());

        return (new CategoryResource($row))->response()->setStatusCode(201);
    }

    public function show(Category $category): CategoryResource
    {
        return new CategoryResource($category);
    }

    public function update(CategoryRequest $request, Category $category): CategoryResource
    {
        $category->update($request->validated());

        return new CategoryResource($category->fresh());
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->delete();

        return response()->json(null, 204);
    }
}
