<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnsubscribeResource;
use App\Models\Unsubscribe;
use App\Support\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-mostly admin list of per-stream opt-outs (the `unsubscribes` table).
 *
 * Filterable by site, stream (subscription/promotion) and email. Deleting a row
 * simply clears that opt-out, so the address may receive that stream again.
 */
class UnsubscribeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filtered($request)
            ->with('site')
            ->orderByDesc('unsubscribed_at');

        return UnsubscribeResource::collection($query->paginate(50)->withQueryString());
    }

    /**
     * Total matching the current filters, as a dedicated COUNT.
     *
     * Built from {@see filtered()} — the same conditions the listing uses — but
     * deliberately without the eager load, the ordering or any column
     * selection: none of them affect the total, and all of them cost time on a
     * table heading for millions of rows. Kept off the listing request so the
     * paginated query never carries the weight of counting.
     */
    public function count(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->filtered($request)->count()]);
    }

    /**
     * The filter conditions, and nothing else. THE single definition of "which
     * unsubscribes is the admin looking at" — the listing, the count and the
     * export all build on it, so the three can never disagree.
     *
     * @return Builder<Unsubscribe>
     */
    private function filtered(Request $request): Builder
    {
        return Unsubscribe::query()
            ->when($request->integer('site_id') ?: null, fn ($q, $id) => $q->where('site_id', $id))
            ->when($this->validType($request), fn ($q, $type) => $q->where('type', $type))
            ->when($this->searchTerm($request), fn ($q, $term) => $q->where('email', 'like', "%{$term}%"));
    }

    /**
     * Sanitised search term, or null when blank.
     *
     * LIKE wildcards are escaped: an address containing `_` (common) otherwise
     * matched any single character, and a stray `%` turned the filter into "match
     * everything" — so searching for a specific opt-out silently returned rows
     * that were not it.
     */
    private function searchTerm(Request $request): ?string
    {
        $term = trim((string) $request->query('search'));

        if ($term === '') {
            return null;
        }

        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = Unsubscribe::with('site')
            ->when($request->integer('site_id') ?: null, fn ($q, $id) => $q->where('site_id', $id))
            ->when($this->validType($request), fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('unsubscribed_at')
            ->cursor()
            ->map(fn (Unsubscribe $u) => [
                $u->email,
                $u->type,
                $u->site?->name ?? '',
                $u->unsubscribed_at?->format('d/m/Y, g:i A') ?? '',
            ]);

        return CsvExport::download(
            'unsubscribes.csv',
            ['Email address', 'Stream', 'Site', 'Unsubscribed at'],
            $rows,
        );
    }

    /** Clear a single opt-out (re-allows that stream for the address). */
    public function destroy(Unsubscribe $unsubscribe): JsonResponse
    {
        $unsubscribe->delete();

        return response()->json(null, 204);
    }

    /** Return the requested stream filter only when it is a known type. */
    private function validType(Request $request): ?string
    {
        $type = (string) $request->query('type');

        return in_array($type, Unsubscribe::TYPES, true) ? $type : null;
    }
}
