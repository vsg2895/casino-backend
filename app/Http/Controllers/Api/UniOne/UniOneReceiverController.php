<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\UniOne;

use App\Http\Controllers\Controller;
use App\Http\Resources\UniOne\UniOneReceiverResource;
use App\Models\UniOne\UniOneReceiver;
use App\Services\UniOne\UniOneReceiverImportService;
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

        // Options for the "Last sent" status filter, read from the DATA rather
        // than from a constant. applyEventStatus() records an unrecognised
        // UniOne value verbatim instead of guessing at it, so a hardcoded list
        // would silently omit whatever UniOne added most recently — and the
        // filter would hide rows the table is showing.
        $lastStatuses = UniOneReceiver::query()
            ->whereNotNull('last_status')
            ->select('last_status')
            ->distinct()
            ->orderBy('last_status')
            ->pluck('last_status')
            ->all();

        return response()->json([
            'data' => [
                'total'     => (int) array_sum($byStatus->all()),
                'by_status' => $byStatus->map(static fn ($v): int => (int) $v),
                'never_sent' => UniOneReceiver::query()->whereNull('last_sent_at')->count(),
                'last_statuses' => $lastStatuses,
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
     * Spreadsheet import — the same shape as the Warmup receivers import.
     *
     * An uploaded .xlsx or .csv with an Email column, streamed in batches, and a
     * summary of rows/imported/duplicates/invalid. Warmup's own import is
     * untouched; this is new code over the shared spreadsheet reader.
     */
    public function import(Request $request, UniOneReceiverImportService $importer): JsonResponse
    {
        $data = $request->validate([
            // 20 MB matches the warmup import's ceiling; xlsx of this size is
            // already hundreds of thousands of rows.
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:20480'],
        ]);

        $file = $request->file('file');

        try {
            $summary = $importer->import(
                $file->getRealPath(),
                strtolower((string) $file->getClientOriginalExtension()),
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('UniOne receiver import failed', [
                'filename' => $file->getClientOriginalName(),
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'The file could not be read. Check that it is a valid .xlsx or .csv with an Email column.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'ok' => true,
            ...$summary,
            'message' => sprintf(
                'Read %d row(s): %d imported, %d duplicate(s) skipped, %d invalid.',
                $summary['rows'],
                $summary['imported'],
                $summary['duplicates'],
                $summary['invalid'],
            ),
        ]);
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

            // ── the "Last sent" column ──────────────────────────────────────
            // `sent` is the coarse cut (has this address ever been mailed?);
            // the pair below narrows the ones that have. Separate from
            // from/to above, which filter on when the address was ADDED —
            // conflating the two is the obvious trap on this screen.
            'sent'      => ['nullable', Rule::in(['never', 'ever'])],
            'sent_from' => ['nullable', 'date'],
            'sent_to'   => ['nullable', 'date', 'after_or_equal:sent_from'],
            'last_status' => ['nullable', 'string', 'max:60'],

            // ── the "Counts" column ─────────────────────────────────────────
            // A send-count window, plus the delivery-health cut that reads
            // the other two counters the column displays.
            'min_sends' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'max_sends' => ['nullable', 'integer', 'min:0', 'max:1000000', 'gte:min_sends'],
            'issues'    => ['nullable', Rule::in(['bounced', 'complained', 'clean'])],
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function filtered(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $sent   = $filters['sent'] ?? null;
        $issues = $filters['issues'] ?? null;

        // A last-sent WINDOW only describes rows that have a last-sent date.
        // Combined with "never sent" it would always return nothing, which
        // reads as a broken screen rather than a contradictory request, so the
        // window is dropped instead of narrowing an already-empty set.
        $window = $sent !== 'never';

        return UniOneReceiver::query()
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['consent_source'] ?? null, fn ($q, $v) => $q->where('consent_source', 'like', "%{$v}%"))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v . ' 23:59:59'))
            // ── Last sent ───────────────────────────────────────────────────
            ->when($sent === 'never', fn ($q) => $q->whereNull('last_sent_at'))
            ->when($sent === 'ever', fn ($q) => $q->whereNotNull('last_sent_at'))
            ->when($window && ($filters['sent_from'] ?? null), fn ($q) => $q->where('last_sent_at', '>=', $filters['sent_from']))
            ->when($window && ($filters['sent_to'] ?? null), fn ($q) => $q->where('last_sent_at', '<=', $filters['sent_to'] . ' 23:59:59'))
            ->when($filters['last_status'] ?? null, fn ($q, $v) => $q->where('last_status', $v))
            // ── Counts ──────────────────────────────────────────────────────
            // isset() rather than ?? null: 0 is a meaningful bound here and
            // `?? null` would discard "max 0 sends", i.e. the never-mailed cut.
            ->when(isset($filters['min_sends']), fn ($q) => $q->where('send_count', '>=', (int) $filters['min_sends']))
            ->when(isset($filters['max_sends']), fn ($q) => $q->where('send_count', '<=', (int) $filters['max_sends']))
            ->when($issues === 'bounced', fn ($q) => $q->where('bounce_count', '>', 0))
            ->when($issues === 'complained', fn ($q) => $q->where('complaint_count', '>', 0))
            ->when($issues === 'clean', fn ($q) => $q->where('bounce_count', 0)->where('complaint_count', 0))
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
            // Optional metadata now. Kept on the editor so an operator who DOES
            // have a consent record can still put it on the row — the send path
            // no longer requires it.
            'consent_source' => ['nullable', 'string', 'max:120'],
            'consent_at'     => ['nullable', 'date', 'before_or_equal:now'],
            'notes'  => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
