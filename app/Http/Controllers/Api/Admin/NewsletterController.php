<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkNewsletterIdsRequest;
use App\Http\Requests\Admin\ImportNewslettersRequest;
use App\Http\Requests\Admin\StoreNewsletterRequest;
use App\Http\Resources\NewsletterResource;
use App\Jobs\ImportNewslettersJob;
use App\Models\Newsletter;
use App\Models\NewsletterImport;
use App\Support\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NewsletterController extends Controller
{
    /** Page size bounds for the admin listing. */
    private const int DEFAULT_PER_PAGE = 50;
    private const int MAX_PER_PAGE = 200;

    public function index(Request $request): AnonymousResourceCollection
    {
        $trashed = $request->boolean('trashed');

        // Driven by the table's rows-per-page control, clamped so a crafted
        // request cannot ask for a 50k-row page.
        $perPage = min(
            max($request->integer('per_page') ?: self::DEFAULT_PER_PAGE, 1),
            self::MAX_PER_PAGE,
        );

        $query = $this->filtered($request)
            ->with('site')
            ->when($trashed, fn ($q) => $q->orderByDesc('deleted_at'), fn ($q) => $q->latest())
            // Tiebreaker, and not optional: a bulk import writes hundreds of rows
            // with an identical created_at, and MySQL does not guarantee a stable
            // order among ties. Without this, paging through an imported list
            // repeats some rows and skips others.
            ->orderByDesc('id');

        return NewsletterResource::collection($query->paginate($perPage));
    }

    /**
     * Total matching the current filters, as a dedicated COUNT.
     *
     * Built from {@see filtered()} — the same site / trash conditions the
     * listing uses — with no eager load, no ordering and no column selection.
     * At 50k+ subscribers per site the `with('site')` alone would cost a second
     * query for nothing, since the total does not depend on it.
     */
    public function count(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->filtered($request)->count()]);
    }

    /**
     * The filter conditions, and nothing else. THE single definition of "which
     * subscribers is the admin looking at", shared by the listing and the count
     * so the two can never disagree.
     *
     * @return Builder<Newsletter>
     */
    private function filtered(Request $request): Builder
    {
        $siteId = $request->integer('site_id') ?: null;
        $verified = $this->verifiedFilter($request);

        return Newsletter::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->when($request->boolean('trashed'), fn ($q) => $q->onlyTrashed())
            ->when($verified !== null, fn ($q) => $q->where('verified', $verified));
    }

    /**
     * The ?verified filter as a tri-state: true, false, or null for "all".
     *
     * `$request->boolean()` is unusable here — it returns false both for
     * "verified=0" and for an absent parameter, which would silently turn "show
     * everyone" into "show only unverified". Presence has to be checked before
     * the value is interpreted.
     */
    private function verifiedFilter(Request $request): ?bool
    {
        $value = $request->query('verified');

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function store(StoreNewsletterRequest $request): NewsletterResource
    {
        $newsletter = Newsletter::firstOrCreate([
            'site_id' => $request->integer('site_id'),
            'email'   => $request->validated('email'),
        ]);

        return new NewsletterResource($newsletter->load('site'));
    }

    /**
     * Queue a bulk import of subscribers from an uploaded .xlsx / .csv with an
     * "Email" column. Existing (or previously-unsubscribed) addresses are
     * kept/restored, not duplicated. Imported contacts are added silently — no
     * welcome email is sent (this is a list import, not a public subscription).
     *
     * The request only STAGES the file and returns; parsing and writing happen
     * in {@see ImportNewslettersJob} on the high-priority queue. A large list
     * must never be processed inside an HTTP request, where it would burn a
     * php-fpm worker and eventually hit max_execution_time.
     *
     * Responds 202 with the import record; poll {@see importStatus()} for
     * progress and the final counts.
     */
    public function import(ImportNewslettersRequest $request): JsonResponse
    {
        $file = $request->file('file');

        $import = NewsletterImport::create([
            'site_id'  => $request->integer('site_id'),
            'user_id'  => $request->user()?->id,
            'filename' => $file->getClientOriginalName(),
            // Staged on the local disk; the job reads it and deletes it. The
            // worker must therefore share this filesystem with the web process.
            'path'     => $file->store('newsletter-imports', 'local'),
            // Admin's choice: import as already-verified, or (default) unverified.
            'verified' => $request->boolean('verified'),
            'status'   => NewsletterImport::STATUS_QUEUED,
        ]);

        ImportNewslettersJob::dispatch($import->id);

        // Under a synchronous queue the job has already run by now, so report
        // whatever state it reached rather than a stale "queued".
        return $this->importPayload($import->refresh(), Response::HTTP_ACCEPTED);
    }

    /** Progress + outcome of a queued import, polled by the admin panel. */
    public function importStatus(NewsletterImport $import): JsonResponse
    {
        return $this->importPayload($import);
    }

    /** The flat import payload both endpoints answer with. */
    private function importPayload(NewsletterImport $import, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'import_id' => $import->id,
            'status'    => $import->status,
            'finished'  => $import->isFinished(),
            'imported'  => $import->imported,
            'skipped'   => $import->skipped,
            'total'     => $import->total,
            'error'     => $import->error,
            'message'   => $import->summary(),
        ], $status);
    }

    public function export(Request $request): StreamedResponse
    {
        $siteId = $request->integer('site_id') ?: null;

        $rows = Newsletter::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->latest()
            ->cursor()
            ->map(fn (Newsletter $n) => [
                $n->email,
                $n->created_at?->format('d/m/Y, g:i A') ?? '',
            ]);

        return CsvExport::download('newsletter.csv', ['Email address', 'Created at'], $rows);
    }

    /** Soft-delete a single subscriber. */
    public function destroy(Newsletter $newsletter): JsonResponse
    {
        $newsletter->delete();

        return response()->json(null, 204);
    }

    /** Soft-delete the checkbox-selected subscribers. */
    public function bulkDestroy(BulkNewsletterIdsRequest $request): JsonResponse
    {
        $deleted = Newsletter::whereIn('id', $request->ids())->delete();

        return response()->json(['deleted' => $deleted]);
    }

    /** Soft-delete every subscriber, optionally scoped to one site. */
    public function destroyAll(Request $request): JsonResponse
    {
        $siteId = $request->integer('site_id') ?: null;

        $deleted = Newsletter::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->delete();

        return response()->json(['deleted' => $deleted]);
    }

    /** Restore a single soft-deleted subscriber. */
    public function restore(Newsletter $newsletter): NewsletterResource
    {
        $newsletter->restore();

        return new NewsletterResource($newsletter->load('site'));
    }

    /** Restore the checkbox-selected soft-deleted subscribers. */
    public function bulkRestore(BulkNewsletterIdsRequest $request): JsonResponse
    {
        $restored = Newsletter::onlyTrashed()->whereIn('id', $request->ids())->restore();

        return response()->json(['restored' => $restored]);
    }

    /** Permanently delete a single trashed subscriber. */
    public function forceDestroy(Newsletter $newsletter): JsonResponse
    {
        $newsletter->forceDelete();

        return response()->json(null, 204);
    }

    /** Permanently delete the checkbox-selected trashed subscribers. */
    public function bulkForceDestroy(BulkNewsletterIdsRequest $request): JsonResponse
    {
        $deleted = Newsletter::onlyTrashed()->whereIn('id', $request->ids())->forceDelete();

        return response()->json(['deleted' => $deleted]);
    }
}
