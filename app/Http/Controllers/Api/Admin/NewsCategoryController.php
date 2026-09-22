<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreNewsCategoryRequest;
use App\Http\Requests\Admin\UpdateNewsCategoryRequest;
use App\Http\Resources\NewsCategoryResource;
use App\Jobs\RevalidateNextJsSites;
use App\Models\NewsCategory;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * News categories for ONE site.
 *
 * Nested under a site, like Guides and News themselves — each domain owns its
 * editorial sections. The admin listing shows inactive categories; the public
 * feed cannot, which is the point of having a switch.
 */
class NewsCategoryController extends Controller
{
    public function index(Site $site): AnonymousResourceCollection
    {
        return NewsCategoryResource::collection(
            NewsCategory::query()
                ->where('site_id', $site->id)
                ->withCount('articles')
                ->ordered()
                ->get(),
        );
    }

    public function store(StoreNewsCategoryRequest $request, Site $site): JsonResponse
    {
        $category = NewsCategory::create([...$request->validated(), 'site_id' => $site->id]);

        $this->refresh($site);

        return (new NewsCategoryResource($category))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateNewsCategoryRequest $request, Site $site, NewsCategory $newsCategory): JsonResponse
    {
        $this->assertBelongsTo($site, $newsCategory);

        $newsCategory->update($request->validated());

        $this->refresh($site);

        return response()->json([
            'data' => (new NewsCategoryResource($newsCategory->loadCount('articles')))->resolve(),
        ]);
    }

    /**
     * Delete a category without deleting what was filed under it.
     *
     * The foreign key is nullOnDelete, so posts survive and become
     * uncategorised — they stay published and keep their URLs, they simply lose
     * their badge. Losing an editor's articles because a label was removed would
     * be wildly out of proportion.
     */
    public function destroy(Site $site, NewsCategory $newsCategory): JsonResponse
    {
        $this->assertBelongsTo($site, $newsCategory);

        $orphaned = $newsCategory->articles()->count();

        $newsCategory->delete();

        $this->refresh($site);

        return response()->json([
            'deleted'  => true,
            'orphaned' => $orphaned,
            'message'  => $orphaned === 0
                ? 'Category deleted.'
                : "Category deleted. {$orphaned} post(s) are now uncategorised — still published, just without a section label.",
        ]);
    }

    /** A category from another site is not this site's to edit. */
    private function assertBelongsTo(Site $site, NewsCategory $category): void
    {
        abort_if($category->site_id !== $site->id, Response::HTTP_NOT_FOUND);
    }

    private function refresh(Site $site): void
    {
        SiteCache::flushSite($site->id);
        RevalidateNextJsSites::dispatch(['news'], [$site->id]);
    }
}
