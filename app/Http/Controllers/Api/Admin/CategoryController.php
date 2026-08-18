<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Jobs\InvalidateCasinoCache;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::withCount('casinos')->ordered()->get()
        );
    }

    public function store(StoreCategoryRequest $request): CategoryResource
    {
        $category = Category::create($request->validated());

        return new CategoryResource($category);
    }

    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        $category->update($request->validated());

        $this->revalidate($category);

        return new CategoryResource($category->fresh());
    }

    public function destroy(Category $category): JsonResponse
    {
        // Resolved before the row goes: the pivot rows go with it, so afterwards
        // there is no way left to tell which sites were showing this category.
        $siteIds = $this->siteIdsFor($category);

        $category->delete();

        $this->dispatchInvalidation($siteIds);

        return response()->json(null, 204);
    }

    /**
     * Sites that publish at least one casino in this category.
     *
     * @return list<int>
     */
    private function siteIdsFor(Category $category): array
    {
        return $category->casinos()
            ->join('casino_site as pivot', 'casinos.id', '=', 'pivot.casino_id')
            ->distinct()
            ->pluck('pivot.site_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function revalidate(Category $category): void
    {
        $this->dispatchInvalidation($this->siteIdsFor($category));
    }

    /**
     * Categories are global master data, but they are RENDERED per site — the
     * nav, the /categories index and each category's casino catalog are all
     * cached behind that site's tag. Without this, renaming or reordering a
     * category left every public site serving the old label for up to an hour,
     * and Next.js was never told to rebuild at all.
     *
     * @param  list<int>  $siteIds
     */
    private function dispatchInvalidation(array $siteIds): void
    {
        $siteIds = array_values(array_unique(array_filter($siteIds)));

        if ($siteIds === []) {
            return;
        }

        InvalidateCasinoCache::dispatch($siteIds, ['categories', 'casinos']);
    }
}
