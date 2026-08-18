<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCmsPageRequest;
use App\Http\Requests\Admin\UpdateCmsPageRequest;
use App\Http\Resources\CmsPageResource;
use App\Jobs\RevalidateNextJsSites;
use App\Models\CmsPage;
use App\Models\Site;
use App\Services\CmsPageService;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CmsPageController extends Controller
{
    public function __construct(private readonly CmsPageService $service) {}

    /**
     * Pages are per-site — bust the owning site's backend cache and trigger a
     * Next.js revalidation for that site only.
     */
    private function revalidate(CmsPage $page): void
    {
        $this->revalidateSite($page->site_id, (string) $page->slug);
    }

    /** Same invalidation, from primitives — usable after the row is gone. */
    private function revalidateSite(int $siteId, string $slug): void
    {
        SiteCache::flushSite($siteId);

        $site = Site::query()->where('id', $siteId)->where('active', true)->first();

        if ($site !== null) {
            RevalidateNextJsSites::dispatch(['page:' . $slug], [$site->id]);
        }
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CmsPage::class);

        $siteId = $request->integer('site_id') ?: null;

        return CmsPageResource::collection($this->service->paginate(50, $siteId));
    }

    public function store(StoreCmsPageRequest $request): JsonResponse
    {
        // Authorization handled by StoreCmsPageRequest::authorize().
        $page = $this->service->create($request->validated());

        $this->revalidate($page);

        return (new CmsPageResource($page))->response()->setStatusCode(201);
    }

    public function show(CmsPage $page): CmsPageResource
    {
        $this->authorize('view', $page);

        return new CmsPageResource($page);
    }

    public function update(UpdateCmsPageRequest $request, CmsPage $page): CmsPageResource
    {
        // Authorization handled by UpdateCmsPageRequest::authorize().
        $updated = $this->service->update($page, $request->validated());

        $this->revalidate($updated);

        return new CmsPageResource($updated);
    }

    public function destroy(CmsPage $page): JsonResponse
    {
        $this->authorize('delete', $page);

        // Delete FIRST, then revalidate. Pinging beforehand lets Next.js
        // regenerate the page while the row is still published, re-caching the
        // very content this request removes.
        $siteId = $page->site_id;
        $slug = (string) $page->slug;

        $this->service->delete($page);

        $this->revalidateSite($siteId, $slug);

        return response()->json(null, 204);
    }
}
