<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnsubscribeResource;
use App\Models\Unsubscribe;
use App\Support\CsvExport;
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
        $query = Unsubscribe::with('site')
            ->when($request->integer('site_id') ?: null, fn ($q, $id) => $q->where('site_id', $id))
            ->when($this->validType($request), fn ($q, $type) => $q->where('type', $type))
            ->when(trim((string) $request->query('search')), fn ($q, $term) => $q->where('email', 'like', "%{$term}%"))
            ->orderByDesc('unsubscribed_at');

        return UnsubscribeResource::collection($query->paginate(50)->withQueryString());
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
