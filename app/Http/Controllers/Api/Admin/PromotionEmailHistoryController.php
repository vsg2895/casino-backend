<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionEmailHistoryResource;
use App\Models\PromotionEmailHistory;
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
        $query = PromotionEmailHistory::query()
            ->with('site')
            ->when($request->integer('site_id') ?: null, fn ($q, $id) => $q->where('site_id', $id))
            ->when($request->date('from'), fn ($q, $from) => $q->where('sent_date', '>=', $from->toDateString()))
            ->when($request->date('to'), fn ($q, $to) => $q->where('sent_date', '<=', $to->toDateString()))
            ->when(
                $this->searchTerm($request),
                fn ($q, $term) => $q->where('email', 'like', $term . '%'),
            )
            ->orderByDesc('sent_date')
            ->orderByDesc('id');

        return PromotionEmailHistoryResource::collection($query->paginate(50)->withQueryString());
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
