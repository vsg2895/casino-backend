<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CasinoReviewResource;
use App\Jobs\InvalidateCasinoCache;
use App\Models\CasinoReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Moderation for visitor-written reviews.
 *
 * Three actions and no more: publish, hide, delete permanently. There is no
 * edit — a moderator rewriting someone's review and leaving their name on it is
 * not moderation, and the admin has no legitimate reason to do it.
 *
 * Every action that changes what the public sees flushes that SITE's cache and
 * queues a Next.js revalidation. Scoped to the review's own site rather than the
 * whole network: a review belongs to one domain, so rebuilding the other five
 * would be waste.
 */
class CasinoReviewController extends Controller
{
    private const int DEFAULT_PER_PAGE = 25;
    private const int MAX_PER_PAGE = 100;

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            max($request->integer('per_page') ?: self::DEFAULT_PER_PAGE, 1),
            self::MAX_PER_PAGE,
        );

        return CasinoReviewResource::collection(
            $this->filtered($request)
                ->with(['site:id,name', 'casino:id,name,slug'])
                ->latest('id')
                ->paginate($perPage),
        );
    }

    /** Total matching the current filters, as a dedicated COUNT. */
    public function count(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->filtered($request)->count()]);
    }

    /** How many are waiting to be looked at — drives the sidebar badge. */
    public function pendingCount(): JsonResponse
    {
        return response()->json([
            'total' => CasinoReview::where('status', CasinoReview::STATUS_PENDING)->count(),
        ]);
    }

    /**
     * Show or hide one review.
     *
     * A single endpoint taking a boolean rather than separate publish/hide
     * routes, so the two can never drift apart — and so the UI can bind it to a
     * toggle.
     */
    public function setVisibility(Request $request, CasinoReview $casinoReview): CasinoReviewResource
    {
        $validated = $request->validate(['published' => ['required', 'boolean']]);

        $casinoReview->setPublished((bool) $validated['published']);

        $this->invalidate($casinoReview);

        return new CasinoReviewResource($casinoReview->load(['site:id,name', 'casino:id,name,slug']));
    }

    /**
     * Permanent delete. There is no soft delete on this model, so the row is
     * gone — which is what "permanently delete" has to mean for a moderator
     * removing abuse.
     */
    public function destroy(CasinoReview $casinoReview): JsonResponse
    {
        // Captured before the row goes: afterwards there is nothing left to say
        // which site was showing it.
        $wasPublished = $casinoReview->isPublished();
        $siteId = (int) $casinoReview->site_id;

        $casinoReview->delete();

        // Only a PUBLISHED review was visible, so only its removal changes a
        // public page. Deleting spam that never left the queue rebuilds nothing.
        if ($wasPublished) {
            InvalidateCasinoCache::dispatch([$siteId], ['reviews', 'casinos']);
        }

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Bulk publish / hide / delete, for clearing a queue in one pass.
     *
     * Deliberately loops rather than issuing one mass UPDATE: publishing has to
     * stamp `published_at` per row, and the affected sites have to be collected
     * so each one is invalidated exactly once. The 500 cap keeps that bounded.
     */
    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:publish,hide,delete'],
            'ids'    => ['required', 'array', 'min:1', 'max:500'],
            'ids.*'  => ['integer'],
        ]);

        $reviews = CasinoReview::whereIn('id', $validated['ids'])->get();
        $siteIds = [];
        $affected = 0;

        foreach ($reviews as $review) {
            $wasVisible = $review->isPublished();

            match ($validated['action']) {
                'publish' => $review->setPublished(true),
                'hide'    => $review->setPublished(false),
                'delete'  => $review->delete(),
            };

            // A change matters to the public only if the row was visible before
            // or is visible now.
            if ($wasVisible || $validated['action'] === 'publish') {
                $siteIds[] = (int) $review->site_id;
            }

            $affected++;
        }

        foreach (array_unique($siteIds) as $siteId) {
            InvalidateCasinoCache::dispatch([$siteId], ['reviews', 'casinos']);
        }

        return response()->json(['affected' => $affected]);
    }

    /**
     * The filter set, shared by the listing and the count so the two can never
     * disagree about how many rows match.
     *
     * @return Builder<CasinoReview>
     */
    private function filtered(Request $request): Builder
    {
        $query = CasinoReview::query()->search($request->query('search'));

        if (in_array($request->query('status'), CasinoReview::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('site_id')) {
            $query->where('site_id', $request->integer('site_id'));
        }

        if ($request->filled('casino_id')) {
            $query->where('casino_id', $request->integer('casino_id'));
        }

        if ($request->filled('rating')) {
            $query->where('rating', $request->integer('rating'));
        }

        return $query;
    }

    private function invalidate(CasinoReview $review): void
    {
        InvalidateCasinoCache::dispatch([(int) $review->site_id], ['reviews', 'casinos']);
    }
}
