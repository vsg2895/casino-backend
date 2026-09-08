<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\NavItemResource;
use App\Models\NavItem;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;

/**
 * This site's header and footer menus.
 *
 * Both in ONE response: the layout renders both on every page, so two endpoints
 * would mean two round trips for one render.
 *
 * An empty menu is returned as an empty array rather than a 404. The front end
 * treats empty as "fall back to the links in code", which is what keeps this
 * feature invisible on the five sites that have not adopted it.
 */
class NavigationController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $data = SiteCache::remember($site->id, ['navigation'], 'navigation:site:' . $site->id, 3600, function () use ($site) {
            $items = NavItem::query()->where('site_id', $site->id)->visible()->get();

            return [
                'header' => NavItemResource::collection(
                    $items->where('location', NavItem::LOCATION_HEADER)->values()
                )->resolve(),
                'footer' => NavItemResource::collection(
                    $items->where('location', NavItem::LOCATION_FOOTER)->values()
                )->resolve(),
            ];
        });

        return response()->json(['data' => $data]);
    }
}
