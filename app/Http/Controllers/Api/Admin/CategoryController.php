<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderCategoriesRequest;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Jobs\InvalidateCasinoCache;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

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

    /**
     * Set the order categories are shown in, across EVERY site at once.
     *
     * Takes the ids in the order they should appear and writes `sort_order` as
     * the index — so the list is always 0,1,2,… with no gaps and no ties.
     *
     * WHY A WHOLE-LIST ENDPOINT rather than patching one row's number. Position
     * is relative: "put Crypto Oriented second" is a statement about the whole
     * row of chips, not about one of them. Editing a single number cannot say
     * it — with the values this platform shipped (two categories both at 0,
     * then 1, 2, 3…) setting one to 2 produced a TIE, and the tie broke on name,
     * so the category landed third or fourth depending on its spelling. An
     * operator typing "2" and getting the fourth chip is right to call that
     * broken.
     *
     * Renumbering everything from the submitted order makes the admin's
     * position and the public position the same fact. The UI can then show 1,
     * 2, 3 and mean it.
     *
     * NOT per site, deliberately. `categories` is global master data shared by
     * every registered domain; there is no per-site pivot to carry an override,
     * and the request here is explicitly that one order applies everywhere.
     *
     * One transaction: a half-applied reorder leaves duplicate positions, which
     * is the exact state this endpoint exists to prevent.
     */
    public function reorder(ReorderCategoriesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var list<int> $ids */
        $ids = array_map('intval', $validated['ids']);

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $position => $id) {
                Category::query()->whereKey($id)->update(['sort_order' => $position]);
            }
        });

        /*
         * Every site that renders ANY of these categories, not just the moved
         * one: reordering changes the sequence the whole row is drawn in, so a
         * site showing only the categories that did not move still has a stale
         * row cached.
         */
        $this->dispatchInvalidation(
            Category::query()
                ->whereIn('id', $ids)
                ->get()
                ->flatMap(fn (Category $category): array => $this->siteIdsFor($category))
                ->all(),
        );

        return response()->json([
            'reordered' => count($ids),
            'data' => CategoryResource::collection(
                Category::withCount('casinos')->ordered()->get()
            )->resolve(),
        ]);
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
