<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\RespondsWithQueuedRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkWarmupEmailIdsRequest;
use App\Http\Requests\Admin\ImportWarmupEmailsRequest;
use App\Http\Requests\Admin\SendWarmupEmailsRequest;
use App\Http\Requests\Admin\StoreWarmupEmailRequest;
use App\Http\Requests\Admin\UpdateWarmupEmailRequest;
use App\Http\Resources\WarmupEmailResource;
use App\Http\Resources\WarmupSendRecipientResource;
use App\Models\WarmupEmail;
use App\Models\WarmupSend;
use App\Models\WarmupSendRecipient;
use App\Services\Mail\EmailTemplateCatalog;
use App\Services\Mail\WarmupMailResolver;
use App\Services\WarmupImportService;
use App\Services\WarmupRunService;
use App\Support\DayWindow;
use App\Support\Spreadsheet\EmailSpreadsheetReader;
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
 * shape. The import reuses {@see EmailSpreadsheetReader}
 * and the send reuses the .env SMTP mailer — neither is reimplemented here.
 */
class WarmupEmailController extends Controller
{
    use RespondsWithQueuedRun;

    public function __construct(private readonly EmailTemplateCatalog $templates) {}

    /** Page size bounds for the admin listing. */
    private const int DEFAULT_PER_PAGE = 50;

    private const int MAX_PER_PAGE = 200;

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
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'The file could not be read. Check that it is a valid .xlsx or .csv.',
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

    /**
     * Templates a warmup run may use — the catalog, filtered by the warmup allow-list.
     *
     * Served from {@see WarmupMailResolver::ALLOWED_TEMPLATES} so the dropdown,
     * the validation rule and the send path all read ONE allow-list. Registering a
     * future template in the catalog makes it appear here automatically.
     */
    public function templates(): JsonResponse
    {
        $allowed = array_values(array_filter(
            $this->templates->types(),
            static fn (array $type): bool => WarmupMailResolver::supports($type['value']),
        ));

        return response()->json(['data' => $allowed]);
    }

    /**
     * Queue a warmup run: one site's email template, sent to a chosen number of
     * addresses — or to the whole list when no count is given.
     *
     * The run itself is {@see WarmupRunService::start()}; a refusal answers
     * through {@see RespondsWithQueuedRun}.
     */
    public function send(SendWarmupEmailsRequest $request, WarmupRunService $runs): JsonResponse
    {
        $outcome = $runs->start(
            (string) $request->validated('template'),
            $request->recipientLimit(),
            $request->cooldownDays(),
            $request->user()?->id,
        );

        if (! $outcome->wasQueued()) {
            return $this->refusedRun($outcome);
        }

        return response()->json([
            'ok' => true,
            'send_id' => $outcome->sendId,
            'recipients' => $outcome->recipients,
            'message' => $outcome->message,
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Stop the current warmup run and free the lock — the recovery path.
     *
     * Always answers, and says honestly which steps took effect; only a lock
     * that could not be freed is a 500. See {@see WarmupRunService::cancel()}.
     */
    public function cancel(WarmupRunService $runs): JsonResponse
    {
        $result = $runs->cancel();

        return response()->json([
            'ok' => $result['lock_freed'],
            'warmup_send_id' => $result['warmup_send_id'],
            'lock_freed' => $result['lock_freed'],
            'queued_work_stopped' => $result['queued_work_stopped'],
            'message' => $result['message'],
        ], $result['lock_freed'] ? Response::HTTP_OK : Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Who a run with these settings would reach right now.
     *
     * The same "preview the audience" contract as `schedules/{schedule}/recipients`
     * and `newsletter-phones/recipients`. See {@see WarmupRunService::audience()}.
     */
    public function recipients(Request $request, WarmupRunService $runs): JsonResponse
    {
        return response()->json([
            'data' => $runs->audience($this->clampedCount($request), $this->clampedCooldown($request)),
        ]);
    }

    /**
     * The ACTUAL ADDRESSES a run with these settings would take, in send order.
     *
     * The counts endpoint above answers "how many"; this answers "which ones" —
     * the same question the Mailgun receivers screen answers with its "Preview
     * batch" button. Read-only. See {@see WarmupRunService::preview()}.
     */
    public function recipientsPreview(Request $request, WarmupRunService $runs): AnonymousResourceCollection
    {
        $preview = $runs->preview($this->clampedCount($request), $this->clampedCooldown($request));

        return WarmupEmailResource::collection($preview['rows'])->additional(['meta' => $preview['meta']]);
    }

    /**
     * The MOST RECENT warmup run, summarised — or null if none has happened.
     *
     * The question an operator has on opening the screen the next morning: "how
     * many went out last time?". See {@see WarmupRunService::lastRun()}.
     */
    public function lastSend(WarmupRunService $runs): JsonResponse
    {
        // Null is not a 404: "no run yet" is a normal state of a working screen,
        // and the client renders nothing rather than an error.
        return response()->json(['data' => $runs->lastRun()]);
    }

    /**
     * Per-address delivery history: which address, from which site, with which
     * template, and when.
     *
     * Read-only and paginated, matching `promotion-history` and
     * `newsletter-phones/history`. The site is eager-loaded so a page costs two
     * queries rather than one per row.
     */
    public function history(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            max($request->integer('per_page') ?: self::DEFAULT_PER_PAGE, 1),
            self::MAX_PER_PAGE,
        );

        $query = $this->filteredHistory($request)
            ->with('site:id,name,domain')
            ->newest();

        return WarmupSendRecipientResource::collection($query->paginate($perPage));
    }

    /**
     * Delete one row of the delivery history.
     *
     * The row, not the address: `warmup_emails` is the list, this is the log of
     * what was sent to it, and removing a log line must not take an address off
     * the list.
     */
    public function destroyHistory(WarmupSendRecipient $recipient): JsonResponse
    {
        $recipient->delete();

        return response()->json(null, 204);
    }

    /** Total matching the current history filters, as a dedicated COUNT. */
    public function historyCount(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->filteredHistory($request)->count()]);
    }

    /**
     * The history filter conditions, and nothing else. Shared by the listing and
     * the count so the two can never disagree.
     *
     * @return Builder<WarmupSendRecipient>
     */
    private function filteredHistory(Request $request): Builder
    {
        /*
         * The operator's own IANA timezone, sent by the browser.
         *
         * `sent_from`/`sent_to` are CALENDAR DAYS picked from a local calendar
         * and `sent_at` is stored in UTC, so the zone is what decides where the
         * operator's day starts. Absent falls back to UTC — exactly what this
         * filter did before. See App\Support\DayWindow.
         *
         * Read straight off the query string rather than validated: this is a
         * read-only history listing whose other filters are deliberately
         * forgiving of a stale bookmark, and DayWindow ignores a zone it does
         * not recognise rather than throwing.
         */
        $tz = $this->filterTimezone($request);

        return WarmupSendRecipient::query()
            ->search($request->query('search'))
            ->forSite($request->query('site_id'))
            ->forTemplate($request->query('template'))
            ->withStatus($request->query('status'))
            ->sentFrom($request->query('sent_from'), $tz)
            ->sentUntil($request->query('sent_to'), $tz);
    }

    /**
     * Requested recipient cap from a preview query string, or null for "everyone".
     *
     * `filled()` + `integer()` rather than `query()` + a cast: it distinguishes an
     * absent parameter from an explicit 0, and it is array-safe — `?count[]=1`
     * would otherwise reach an `(int)` cast.
     */
    private function clampedCount(Request $request): ?int
    {
        if (! $request->filled('count')) {
            return null;
        }

        return max(1, $request->integer('count'));
    }

    /**
     * Cooldown from a preview query string, clamped to the permitted range.
     *
     * Clamped rather than validated: this is a read-only preview, and a nonsense
     * value should show the admin a sane number instead of a 422. The send itself
     * validates properly — see {@see SendWarmupEmailsRequest}.
     */
    private function clampedCooldown(Request $request): ?int
    {
        if (! $request->filled('cooldown_days')) {
            return null;
        }

        return min(
            WarmupSend::MAX_COOLDOWN_DAYS,
            max(WarmupSend::MIN_COOLDOWN_DAYS, $request->integer('cooldown_days')),
        );
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

    /**
     * The caller's IANA timezone, or null.
     *
     * Null for anything unrecognised, which DayWindow then reads as UTC — the
     * behaviour every one of these filters had before it learned about zones.
     */
    private function filterTimezone(Request $request): ?string
    {
        $tz = $request->query('tz');

        return (is_string($tz) && DayWindow::isValidTimezone($tz)) ? $tz : null;
    }
}
