<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;

/**
 * Which optional surfaces this site publishes.
 *
 * The Next.js apps read this to decide what to RENDER — whether to show the
 * Countries link in the nav, whether `/countries` should exist at all. It is
 * advisory only: every gated endpoint enforces its own switch server-side, so a
 * site that ignores this response still gets a 404 from the data.
 *
 * Deliberately NOT cached through SiteCache. The payload is two integers' worth
 * of data read from a row the middleware has already loaded, and caching it
 * would mean an admin's toggle took up to an hour to take effect on a surface
 * whose whole purpose is to appear and disappear on demand.
 */
class SiteFeatureController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        return response()->json(['data' => [
            'countries_enabled' => (bool) $site->countries_enabled,
            'reviews_enabled'   => (bool) $site->reviews_enabled,
            'operator_profile_enabled' => (bool) $site->operator_profile_enabled,
            'guides_enabled'    => (bool) $site->guides_enabled,
            // Needs BOTH switches. The forum is a view onto reviews, so a site
            // that has stopped collecting them has no forum either — reporting
            // it as available would put a link in the header that 404s.
            'forum_enabled'     => (bool) $site->reviews_enabled && $site->forumSettings()['enabled'],
        ]]);
    }
}
