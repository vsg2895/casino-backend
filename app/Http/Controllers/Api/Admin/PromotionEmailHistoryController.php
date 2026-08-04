<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionEmailHistoryResource;
use App\Models\PromotionEmailHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only admin view of promotion delivery history.
 *
 * Every filter maps to an index / partition-pruning path:
 *  - site_id + sent_date range → (site_id, sent_date) index + monthly partition
 *    pruning, so only the relevant months are touched.
 *  - email search → a single leading-anchored `LIKE 'term%'` (prefix), which an
 *    index can serve — never a `%term%` full scan.
 * Ordered newest-first; the `site` relation is eager-loaded (no N+1).
 */
class PromotionEmailHistoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filtered($request)
            ->with('site')
            ->orderByDesc('sent_date')
            ->orderByDesc('id');

        return PromotionEmailHistoryResource::collection($query->paginate(50)->withQueryString());
    }

    /**
     * Total matching the current filters, as a dedicated COUNT.
     *
     * This table is the one that genuinely reaches millions of rows — one entry
     * per address per campaign — so the count is kept off the listing request
     * entirely. It carries the filters and nothing else: no eager load, no
     * ordering, no column list. Date filters matter most here, because
     * constraining `sent_date` is what lets MySQL prune the monthly partitions
     * instead of counting the whole table.
     */
    public function count(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->filtered($request)->count()]);
    }

    /**
     * The filter conditions, and nothing else. THE single definition of "which
     * history rows is the admin looking at", shared by the listing and the
     * count so the two can never disagree.
     *
     * @return Builder<PromotionEmailHistory>
     */
    private function filtered(Request $request): Builder
    {
        return PromotionEmailHistory::query()
            ->when($request->integer('site_id') ?: null, fn ($q, $id) => $q->where('site_id', $id))
            ->when($request->date('from'), fn ($q, $from) => $q->where('sent_date', '>=', $from->toDateString()))
            ->when($request->date('to'), fn ($q, $to) => $q->where('sent_date', '<=', $to->toDateString()))
            ->when(
                $this->statusFilter($request),
                fn ($q, $status) => $q->where('status', $status),
            )
            ->when(
                $this->searchTerm($request),
                fn ($q, $term) => $q->where('email', 'like', $term . '%'),
            );
    }

    /** Optional outcome filter; anything but a known status is ignored. */
    private function statusFilter(Request $request): ?string
    {
        $status = (string) $request->query('status');

        return in_array($status, PromotionEmailHistory::STATUSES, true) ? $status : null;
    }

    /** Sanitised prefix search term (only the leading part is matched). */
    private function searchTerm(Request $request): ?string
    {
        $term = trim((string) $request->query('search'));
        if ($term === '') {
            return null;
        }

        // Escape LIKE wildcards so user input can't turn into a %..% scan.
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
