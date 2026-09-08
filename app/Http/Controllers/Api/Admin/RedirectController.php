<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRedirectRequest;
use App\Http\Requests\Admin\UpdateRedirectRequest;
use App\Http\Resources\RedirectResource;
use App\Jobs\RevalidateNextJsSites;
use App\Models\Redirect;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Redirect rules for one site.
 *
 * Loop protection is layered, because a redirect loop takes a page off the
 * internet and is invisible from the admin:
 *
 *   1. the Form Request rejects a rule whose source equals its destination;
 *   2. {@see assertNoCycle()} here rejects a rule that would complete an A→B,
 *      B→A pair;
 *   3. the front end follows exactly ONE hop and never re-resolves a
 *      destination, so even a chain nobody caught cannot spin.
 *
 * Any one of the three would usually be enough. All three exist because the
 * failure mode is total and the cost of the checks is nothing.
 */
class RedirectController extends Controller
{
    public function index(Site $site): AnonymousResourceCollection
    {
        return RedirectResource::collection(
            Redirect::query()
                ->where('site_id', $site->id)
                ->orderByDesc('hits')
                ->orderBy('source_path')
                ->get(),
        );
    }

    public function store(StoreRedirectRequest $request, Site $site): JsonResponse
    {
        $validated = $request->validated();

        if ($conflict = $this->assertNoCycle($site, $validated['source_path'], $validated['destination_path'])) {
            return $conflict;
        }

        $redirect = Redirect::create([...$validated, 'site_id' => $site->id]);

        $this->refresh($site);

        return (new RedirectResource($redirect))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateRedirectRequest $request, Site $site, Redirect $redirect): JsonResponse
    {
        abort_if($redirect->site_id !== $site->id, Response::HTTP_NOT_FOUND);

        $validated = $request->validated();

        if ($conflict = $this->assertNoCycle($site, $validated['source_path'], $validated['destination_path'], $redirect->id)) {
            return $conflict;
        }

        $redirect->update($validated);
        $this->refresh($site);

        return response()->json(['data' => (new RedirectResource($redirect))->resolve()]);
    }

    public function destroy(Site $site, Redirect $redirect): JsonResponse
    {
        abort_if($redirect->site_id !== $site->id, Response::HTTP_NOT_FOUND);

        $redirect->delete();
        $this->refresh($site);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Refuse a rule that would create a direct A→B / B→A cycle.
     *
     * Only the direct case is detected. Longer chains are left to the front
     * end's one-hop rule, because walking an arbitrary chain here would need a
     * recursive query for a situation that is rare, self-correcting on the next
     * request, and already harmless.
     */
    private function assertNoCycle(Site $site, string $source, string $destination, ?int $ignoreId = null): ?JsonResponse
    {
        $reverse = Redirect::query()
            ->where('site_id', $site->id)
            ->where('source_path', $destination)
            ->where('destination_path', $source)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if (! $reverse) {
            return null;
        }

        return response()->json([
            'message' => "That would create a loop: {$destination} already redirects back to {$source}.",
            'errors'  => ['destination_path' => ['This destination already redirects back to the source.']],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function refresh(Site $site): void
    {
        SiteCache::flushSite($site->id);
        RevalidateNextJsSites::dispatch(['redirects'], [$site->id]);
    }
}
