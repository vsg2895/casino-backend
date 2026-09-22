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
            'news_enabled'      => (bool) $site->news_enabled,
            'bonus_enabled'     => (bool) $site->bonus_enabled,
            /*
             * ── Two different "forum" flags, and they are not interchangeable ──
             *
             * `forum_enabled` is the REVIEWS FEED at /reviews — the page that
             * used to live at /forum. It needs both switches, because that page
             * is a view onto reviews and a site which stopped collecting them
             * has nothing to show.
             *
             * `community_forum_enabled` is the DISCUSSION BOARD at /forum, a
             * separate feature with its own column, its own accounts and its own
             * tables. It has nothing to do with reviews.
             *
             * The names are confusing and the wire format is the reason: five
             * other sites already read `forum_enabled` as the reviews feed, so
             * renaming it would be a breaking change to a live contract. The new
             * feature took the new name instead.
             */
            'forum_enabled'     => (bool) $site->reviews_enabled && $site->forumSettings()['enabled'],
            'community_forum_enabled' => (bool) $site->forum_enabled,
        ]]);
    }
}
