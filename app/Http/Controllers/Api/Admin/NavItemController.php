<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreNavItemRequest;
use App\Http\Requests\Admin\UpdateNavItemRequest;
use App\Http\Resources\NavItemResource;
use App\Jobs\RevalidateNextJsSites;
use App\Models\NavItem;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Navigation links for one site.
 *
 * Nested under the site because a menu belongs to exactly one domain — there is
 * no cross-site navigation and there should never be.
 *
 * Every write refreshes that ONE site. Navigation is the only content in this
 * application that is genuinely single-site, so the usual "flush every attached
 * site" fan-out would be wrong here.
 */
class NavItemController extends Controller
{
    public function index(Site $site): AnonymousResourceCollection
    {
        return NavItemResource::collection(
            NavItem::query()
                ->where('site_id', $site->id)
                ->orderBy('location')
                ->orderBy('position')
                ->orderBy('id')
                ->get(),
        );
    }

    public function store(StoreNavItemRequest $request, Site $site): JsonResponse
    {
        $validated = $request->validated();

        $item = NavItem::create([
            ...$validated,
            'site_id'  => $site->id,
            // Append to the end of its own menu unless a position was given, so
            // a new link never silently displaces an existing one.
            'position' => $validated['position'] ?? $this->nextPosition($site, $validated['location']),
        ]);

        $this->refresh($site);

        return (new NavItemResource($item))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateNavItemRequest $request, Site $site, NavItem $navItem): JsonResponse
    {
        $this->assertBelongsTo($site, $navItem);

        $navItem->update($request->validated());
        $this->refresh($site);

        return response()->json(['data' => (new NavItemResource($navItem))->resolve()]);
    }

    public function destroy(Site $site, NavItem $navItem): JsonResponse
    {
        $this->assertBelongsTo($site, $navItem);

        $navItem->delete();
        $this->refresh($site);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Persist a new order in one request.
     *
     * Drag-and-drop produces a whole new order at once; saving it as N PATCHes
     * would leave the menu in a half-reordered state if one failed.
     */
    public function reorder(Request $request, Site $site): JsonResponse
    {
        $validated = $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        // Scoped to this site: an id from another site's menu must not be
        // repositionable through this route.
        $items = NavItem::query()
            ->where('site_id', $site->id)
            ->whereIn('id', $validated['ids'])
            ->pluck('id')
            ->all();

        foreach ($validated['ids'] as $position => $id) {
            if (in_array($id, $items, true)) {
                NavItem::query()->whereKey($id)->update(['position' => $position]);
            }
        }

        $this->refresh($site);

        return response()->json(['reordered' => count($items)]);
    }

    /**
     * A nav item reached through the wrong site is a 404, not a 403.
     *
     * The admin is trusted; this guards against a stale tab or a hand-edited
     * URL quietly editing another domain's menu.
     */
    private function assertBelongsTo(Site $site, NavItem $item): void
    {
        abort_if($item->site_id !== $site->id, Response::HTTP_NOT_FOUND);
    }

    private function nextPosition(Site $site, string $location): int
    {
        return (int) NavItem::query()
            ->where('site_id', $site->id)
            ->where('location', $location)
            ->max('position') + 1;
    }

    private function refresh(Site $site): void
    {
        SiteCache::flushSite($site->id);
        RevalidateNextJsSites::dispatch(['navigation'], [$site->id]);
    }
}
