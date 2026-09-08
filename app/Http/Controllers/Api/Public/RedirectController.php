<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Redirect;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;

/**
 * The active redirect rules for this site.
 *
 * Deliberately NARROWER than the admin resource: the front end needs only the
 * three fields it matches and responds with. Ids, hit counts and timestamps
 * would be sent on a request that runs in middleware on every page view.
 *
 * The whole set is returned in one response rather than offering a per-path
 * lookup. Middleware must decide in microseconds, and a network round trip per
 * request would put the API on the critical path of every page load on the site.
 * The list is small by nature — a redirect table is a record of URL changes, not
 * a content type.
 */
class RedirectController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $data = SiteCache::remember($site->id, ['redirects'], 'redirects:site:' . $site->id, 3600, function () use ($site) {
            return Redirect::query()
                ->where('site_id', $site->id)
                ->active()
                ->orderBy('source_path')
                ->get(['source_path', 'destination_path', 'status_code'])
                ->map(static fn (Redirect $r): array => [
                    'source_path'      => $r->source_path,
                    'destination_path' => $r->destination_path,
                    'status_code'      => (int) $r->status_code,
                ])
                ->all();
        });

        return response()->json(['data' => $data]);
    }
}
