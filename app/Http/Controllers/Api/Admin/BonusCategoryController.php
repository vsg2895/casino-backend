<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBonusCategoryRequest;
use App\Http\Requests\Admin\UpdateBonusCategoryRequest;
use App\Http\Resources\BonusCategoryResource;
use App\Jobs\RevalidateNextJsSites;
use App\Models\BonusCategory;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bonus categories — the sub-items under the Bonus menu.
 *
 * Global, so this is a top-level screen rather than one nested under a site.
 * Every change fans out to EVERY site that publishes the Bonus area, because a
 * renamed or hidden category changes both their menu and their home page.
 *
 * The admin listing shows inactive categories; the public endpoint cannot. That
 * asymmetry is the whole point of having a show/hide switch.
 */
class BonusCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return BonusCategoryResource::collection(
            BonusCategory::query()
                ->withCount('specialOffers')
                ->ordered()
                ->get(),
        );
    }

    public function show(BonusCategory $bonusCategory): JsonResponse
    {
        $bonusCategory->loadCount('specialOffers');

        return response()->json(['data' => (new BonusCategoryResource($bonusCategory))->resolve()]);
    }

    public function store(StoreBonusCategoryRequest $request): JsonResponse
    {
        $category = BonusCategory::create($request->validated());

        $this->refresh();

        return (new BonusCategoryResource($category))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateBonusCategoryRequest $request, BonusCategory $bonusCategory): JsonResponse
    {
        $bonusCategory->update($request->validated());

        $this->refresh();

        return response()->json(['data' => (new BonusCategoryResource($bonusCategory->loadCount('specialOffers')))->resolve()]);
    }

    /**
     * Delete a category without deleting what was filed under it.
     *
     * The foreign key is nullOnDelete, so the offers survive and become
     * uncategorised — they simply stop appearing in the Bonus area until they
     * are filed again. Losing an operator's offers because a heading was
     * removed would be a wildly disproportionate consequence.
     */
    public function destroy(BonusCategory $bonusCategory): JsonResponse
    {
        $orphaned = $bonusCategory->specialOffers()->count();

        $bonusCategory->delete();

        $this->refresh();

        return response()->json([
            'deleted'  => true,
            'orphaned' => $orphaned,
            'message'  => $orphaned === 0
                ? 'Category deleted.'
                : "Category deleted. {$orphaned} offer(s) are now uncategorised and will not show in the Bonus area until filed again.",
        ]);
    }

    /**
     * Every site that publishes the Bonus area, because these rows are global.
     *
     * Both tags: `bonus` is the menu and the sections, `special-offers` because
     * the offers listing shares the cache namespace and a recategorised offer
     * changes what it contains.
     */
    private function refresh(): void
    {
        $siteIds = Site::query()->where('bonus_enabled', true)->pluck('id')->all();

        foreach ($siteIds as $siteId) {
            SiteCache::flushSite($siteId);
        }

        if ($siteIds !== []) {
            RevalidateNextJsSites::dispatch(['bonus', 'special-offers'], $siteIds);
        }
    }
}
