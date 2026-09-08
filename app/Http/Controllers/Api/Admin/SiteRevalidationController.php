<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteRevalidationResource;
use App\Models\Site;
use App\Models\SiteRevalidation;
use App\Services\RevalidationService;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Cache health for one site: what happened, and a way to force a rebuild.
 *
 * This is the screen `docs/admin-first.md` requires and the platform has been
 * missing — every other feature's claim that "saving updates the site" was
 * unverifiable until now.
 */
class SiteRevalidationController extends Controller
{
    public function index(Request $request, Site $site): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page') ?: 20, 1), 100);

        return SiteRevalidationResource::collection(
            SiteRevalidation::query()
                ->where('site_id', $site->id)
                ->latest('created_at')
                ->latest('id')
                ->paginate($perPage),
        );
    }

    /**
     * Rebuild this site's cache now.
     *
     * FLUSHES `SiteCache` BEFORE pinging, and the order is the whole point.
     * Revalidating Next alone makes it refetch and receive the same API response
     * that is cached for an hour — the page rebuilds, nothing changes, and the
     * button looks broken while behaving exactly as written.
     *
     * Sends the site's own tag, which every public fetch carries, so this
     * refreshes every page rather than a single collection.
     */
    public function store(Site $site, RevalidationService $revalidation): JsonResponse
    {
        SiteCache::flushSite($site->id);

        $revalidation->revalidate(
            ['casinos', 'categories', 'special-offers', 'social-links', 'navigation', 'redirects', 'articles', 'editorial'],
            [$site->id],
            SiteRevalidation::TRIGGER_MANUAL,
        );

        $latest = SiteRevalidation::query()
            ->where('site_id', $site->id)
            ->latest('id')
            ->first();

        // The attempt row IS the answer — the caller sees the real outcome
        // rather than an optimistic "queued".
        return response()->json([
            'data' => $latest === null ? null : (new SiteRevalidationResource($latest))->resolve(),
        ]);
    }
}
