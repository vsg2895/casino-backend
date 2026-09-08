<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;

/**
 * This site's editorial identity: who stands behind the reviews.
 *
 * A separate endpoint from `features` on purpose. That one answers "which
 * surfaces does this site publish" and is a set of booleans; this is content,
 * and mixing them would make the flags endpoint something the front end has to
 * re-fetch whenever a bio is edited.
 *
 * `author` is null unless the site has BOTH switched the byline on and entered a
 * name. There is no partial state that renders a nameless byline — the whole
 * point of the feature is that a real person is being named.
 */
class EditorialController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $data = SiteCache::remember($site->id, ['editorial'], 'editorial:site:' . $site->id, 3600, fn () => [
            'author' => $site->editorialAuthor(),
            // The CMS page describing how reviews are done. Null means the site
            // has not written one, and the front end then links to nothing —
            // claiming a methodology that is not published is the failure this
            // item is most at risk of.
            'methodology_page_slug' => $site->methodology_page_slug,
        ]);

        return response()->json(['data' => $data]);
    }
}
