<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\UniOne;

use App\Http\Controllers\Controller;
use App\Http\Resources\UniOne\UniOneReceiverResource;
use App\Models\UniOne\UniOneReceiver;
use App\Services\UniOne\UniOneImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The UniOne recipient list. Touches no existing subscriber table. */
class UniOneReceiverController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        $receivers = $this->filtered($filters)
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? self::PER_PAGE);

        return response()->json([
            'data' => UniOneReceiverResource::collection($receivers->items())->resolve(),
            'meta' => [
                'current_page' => $receivers->currentPage(),
                'last_page'    => $receivers->lastPage(),
                'total'        => $receivers->total(),
                'per_page'     => $receivers->perPage(),
            ],
        ]);
    }

    /** Headline numbers for the screen, including how many could be mailed now. */
    public function stats(): JsonResponse
    {
        $byStatus = UniOneReceiver::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => [
                'total'    => (int) array_sum($byStatus->all()),
                'by_status' => $byStatus->map(static fn ($v): int => (int) $v),
                'sendable' => UniOneReceiver::query()->sendable()->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $receiver = UniOneReceiver::query()->create($this->validated($request, null));

        return (new UniOneReceiverResource($receiver))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, UniOneReceiver $uniOneReceiver): UniOneReceiverResource
    {
        $uniOneReceiver->fill($this->validated($request, $uniOneReceiver))->save();

        return new UniOneReceiverResource($uniOneReceiver->refresh());
    }

    public function destroy(UniOneReceiver $uniOneReceiver): JsonResponse
    {
        $uniOneReceiver->delete();

        return response()->json(['deleted' => true]);
    }

    /** Bulk suppress / delete / set status. */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'    => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*'  => ['integer'],
            'action' => ['required', Rule::in(['suppress', 'delete', 'status'])],
            'status' => ['required_if:action,status', Rule::in(UniOneReceiver::STATUSES)],
        ]);

        $affected = DB::transaction(function () use ($data): int {
            $query = UniOneReceiver::query()->whereIn('id', $data['ids']);

            return match ($data['action']) {
                'suppress' => $query->update(['status' => UniOneReceiver::STATUS_SUPPRESSED]),
                'delete'   => $query->delete(),
                'status'   => $query->update(['status' => $data['status']]),
            };
        });

        return response()->json(['data' => ['affected' => $affected, 'action' => $data['action']]]);
    }

    /**
     * CSV import.
     *
     * `dry_run` returns the same report without writing anything — the preview
     * the brief asks for, so an operator sees the verdicts before committing.
     */
    public function import(Request $request, UniOneImportService $importer): JsonResponse
    {
        $data = $request->validate([
            'rows'    => ['required', 'array', 'min:1', 'max:50000'],
            'mapping' => ['required', 'array'],
            'mapping.email' => ['required', 'string'],
            'mapping.name'  => ['nullable', 'string'],
            'mapping.consent_source' => ['nullable', 'string'],
            'mapping.consent_at'     => ['nullable', 'string'],
            'fallback' => ['nullable', 'array'],
            'fallback.consent_source' => ['nullable', 'string', 'max:120'],
            'fallback.consent_at'     => ['nullable', 'date'],
            'dry_run' => ['required', 'boolean'],
        ]);

        $result = $importer->process(
            $data['rows'],
            $data['mapping'],
            $data['fallback'] ?? [],
            (bool) $data['dry_run'],
        );

        return response()->json(['data' => [...$result, 'dry_run' => (bool) $data['dry_run']]]);
    }

    /** Streamed so a large list never builds the whole file in memory. */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->filtered($filters)->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, [
                'email', 'name', 'status', 'consent_source', 'consent_at',
                'last_sent_at', 'last_status', 'send_count', 'bounce_count', 'complaint_count', 'created_at',
            ]);

            $query->chunkById(1000, function ($rows) use ($out): void {
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r->email, $r->name, $r->status, $r->consent_source,
                        $r->consent_at?->toDateTimeString(),
                        $r->last_sent_at?->toDateTimeString(), $r->last_status,
                        $r->send_count, $r->bounce_count, $r->complaint_count,
                        $r->created_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($out);
        }, 'unione-receivers-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'status'         => ['nullable', Rule::in(UniOneReceiver::STATUSES)],
            'consent_source' => ['nullable', 'string', 'max:120'],
            'from'           => ['nullable', 'date'],
            'to'             => ['nullable', 'date', 'after_or_equal:from'],
            'search'         => ['nullable', 'string', 'max:160'],
            'per_page'       => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function filtered(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        return UniOneReceiver::query()
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['consent_source'] ?? null, fn ($q, $v) => $q->where('consent_source', 'like', "%{$v}%"))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v . ' 23:59:59'))
            ->search($filters['search'] ?? null);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?UniOneReceiver $existing): array
    {
        return $request->validate([
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('unione_receivers', 'email')->ignore($existing?->id)->whereNull('deleted_at'),
            ],
            'name'   => ['nullable', 'string', 'max:160'],
            'status' => ['sometimes', Rule::in(UniOneReceiver::STATUSES)],
            // Both required on every write — the compliance rule, stated here as
            // well as in the schema and the scope.
            'consent_source' => ['required', 'string', 'max:120'],
            'consent_at'     => ['required', 'date', 'before_or_equal:now'],
            'notes'  => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
