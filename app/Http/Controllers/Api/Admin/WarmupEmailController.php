<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkWarmupEmailIdsRequest;
use App\Http\Requests\Admin\ImportWarmupEmailsRequest;
use App\Http\Requests\Admin\SendWarmupEmailsRequest;
use App\Http\Requests\Admin\StoreWarmupEmailRequest;
use App\Http\Requests\Admin\UpdateWarmupEmailRequest;
use App\Http\Resources\WarmupEmailResource;
use App\Jobs\SendWarmupBatchJob;
use App\Models\WarmupEmail;
use App\Services\WarmupImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin CRUD for the email-warmup list, plus spreadsheet import and the warmup
 * send itself.
 *
 * Mirrors the newsletter module: shared {@see filtered()} builder behind both the
 * listing and the dedicated COUNT, the same page-size clamp, the same bulk-delete
 * shape. The import reuses {@see \App\Support\Spreadsheet\EmailSpreadsheetReader}
 * and the send reuses the .env SMTP mailer — neither is reimplemented here.
 */
class WarmupEmailController extends Controller
{
    /** Page size bounds for the admin listing. */
    private const int DEFAULT_PER_PAGE = 50;
    private const int MAX_PER_PAGE = 200;

    /** Fallback addresses per queued send job; see config/warmup.php. */
    private const int SEND_BATCH_SIZE = 100;

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            max($request->integer('per_page') ?: self::DEFAULT_PER_PAGE, 1),
            self::MAX_PER_PAGE,
        );

        $query = $this->filtered($request)
            ->latest()
            // Tiebreaker: an import writes hundreds of rows with an identical
            // created_at, and MySQL gives no stable order among ties — without
            // this, paging repeats some rows and skips others.
            ->orderByDesc('id');

        return WarmupEmailResource::collection($query->paginate($perPage));
    }

    /**
     * Total matching the current filters, as a dedicated COUNT — never derived
     * from the paginated query, so the listing carries no counting work.
     */
    public function count(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->filtered($request)->count()]);
    }

    public function store(StoreWarmupEmailRequest $request): JsonResponse
    {
        $email = WarmupEmail::create($request->validated());

        return (new WarmupEmailResource($email))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateWarmupEmailRequest $request, WarmupEmail $warmupEmail): WarmupEmailResource
    {
        $warmupEmail->update($request->validated());

        return new WarmupEmailResource($warmupEmail);
    }

    public function destroy(WarmupEmail $warmupEmail): JsonResponse
    {
        $warmupEmail->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** Hard delete — this table has no soft deletes and needs no trash view. */
    public function bulkDestroy(BulkWarmupEmailIdsRequest $request): JsonResponse
    {
        $deleted = WarmupEmail::query()->whereIn('id', $request->ids())->delete();

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * Import addresses from an .xlsx / .csv.
     *
     * Runs synchronously, unlike the newsletter import: a warmup list is orders
     * of magnitude smaller (hundreds, not tens of thousands), and the admin needs
     * the per-row breakdown back immediately. The parse is still streamed and the
     * writes still batched, so a large file degrades gracefully rather than
     * exhausting memory.
     */
    public function import(ImportWarmupEmailsRequest $request, WarmupImportService $importer): JsonResponse
    {
        $file = $request->file('file');

        try {
            $summary = $importer->import(
                $file->getRealPath(),
                strtolower($file->getClientOriginalExtension()),
            );
        } catch (Throwable $e) {
            Log::error('Warmup import failed', [
                'filename' => $file->getClientOriginalName(),
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'The file could not be read. Check that it is a valid .xlsx or .csv.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'ok'      => true,
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

    /**
     * Queue the warmup send to every address on the list.
     *
     * Fanned out in batches on the LOW queue so the request returns immediately
     * and a long run never occupies a php-fpm worker. Addresses are streamed
     * with a keyset cursor, so the list is never fully materialised.
     */
    public function send(SendWarmupEmailsRequest $request): JsonResponse
    {
        $subject = (string) $request->validated('subject');
        $body = (string) $request->validated('body');

        $queued = 0;
        $batchSize = max(1, (int) config('warmup.send_batch_size', self::SEND_BATCH_SIZE));

        WarmupEmail::query()
            ->orderBy('id')
            ->chunkById($batchSize, function ($rows) use ($subject, $body, &$queued): void {
                $emails = $rows->pluck('email')->all();
                SendWarmupBatchJob::dispatch($emails, $subject, $body);
                $queued += count($emails);
            });

        if ($queued === 0) {
            return response()->json([
                'ok'      => false,
                'message' => 'The warmup list is empty. Add or import addresses first.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        Log::info('Warmup send queued', [
            'recipients' => $queued,
            'admin_id'   => auth()->id(),
        ]);

        return response()->json([
            'ok'         => true,
            'recipients' => $queued,
            'message'    => "Warmup queued for {$queued} address(es).",
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * The filter conditions, and nothing else. Shared by the listing and the
     * count so the two can never disagree.
     *
     * @return Builder<WarmupEmail>
     */
    private function filtered(Request $request): Builder
    {
        return WarmupEmail::query()->search($request->query('search'));
    }
}
