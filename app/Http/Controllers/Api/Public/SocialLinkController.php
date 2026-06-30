<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\SocialLinkResource;
use App\Models\Site;
use App\Models\SocialLink;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;

class SocialLinkController extends Controller
{
    /** Active social links for the current site, ordered for the footer. */
    public function index(): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $data = SiteCache::remember(
            $site->id,
            ['social-links'],
            'social-links:index:site:' . $site->id,
            3600,
            function () use ($site) {
                $links = SocialLink::query()
                    ->where('site_id', $site->id)
                    ->active()
                    ->ordered()
                    ->get();

                return SocialLinkResource::collection($links)->resolve();
            },
        );

        return response()->json(['data' => $data]);
    }
}
