<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CmsPagePublicResource;
use App\Models\Site;
use App\Services\CmsPageService;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;

class CmsPageController extends Controller
{
    public function __construct(private readonly CmsPageService $service) {}

    /**
     * GET /api/v1/public/sites/{site}/pages/{slug}
     * Site-scoped public endpoint — returns the PUBLISHED page belonging to the
     * current site only; 404 for drafts, unknown slugs, or other sites' pages.
     */
    public function show(string $site, string $slug): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $data = SiteCache::remember(
            $site->id,
            ['page:' . $slug],
            'pages:show:site:' . $site->id . ':slug:' . $slug,
            3600,
            fn () => (new CmsPagePublicResource(
                $this->service->getPublishedBySlugOrFail($site->id, $slug)
            ))->resolve(),
        );

        return response()->json(['data' => $data]);
    }
}
