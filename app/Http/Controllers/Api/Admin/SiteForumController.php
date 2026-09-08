<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSiteForumRequest;
use App\Http\Resources\SiteForumResource;
use App\Jobs\InvalidateCasinoCache;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The forum page's rules for one site.
 *
 * Nested under the site because a forum page belongs to exactly one domain, the
 * same reason navigation is.
 *
 * Saving flushes THIS site and revalidates the `reviews` tag — the tag the
 * public feed is cached under. Without that an editor could change the heading
 * and watch nothing happen for an hour, which is precisely the failure
 * docs/admin-first.md exists to prevent.
 */
class SiteForumController extends Controller
{
    /** The settings, created with defaults (and DISABLED) on first access. */
    public function show(Site $site): JsonResponse
    {
        // Pinned to 200: the first GET creates the row, and an idempotent read
        // must not answer 201 because of it.
        return (new SiteForumResource($site->forumOrDefault()))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    public function update(UpdateSiteForumRequest $request, Site $site): JsonResponse
    {
        $forum = $site->forumOrDefault();
        $forum->update($request->validated());

        // 'reviews' because the feed is cached under it; 'navigation' because
        // turning the forum off must also drop the header link, which the
        // layout renders from its own cached fetch.
        InvalidateCasinoCache::dispatch([$site->id], ['reviews', 'navigation']);

        return (new SiteForumResource($forum->refresh()))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
