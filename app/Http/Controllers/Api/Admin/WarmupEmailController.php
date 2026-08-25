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
use App\Http\Resources\WarmupSendRecipientResource;
use App\Jobs\SendWarmupCampaignJob;
use App\Models\Site;
use App\Models\WarmupEmail;
use App\Models\WarmupSend;
use App\Models\WarmupSendRecipient;
use App\Services\Mail\EmailTemplateCatalog;
use App\Services\Mail\WarmupMailResolver;
use App\Services\WarmupImportService;
use App\Services\WarmupRecipientService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
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
    public function __construct(private readonly EmailTemplateCatalog $templates) {}

    /** Page size bounds for the admin listing. */
    private const int DEFAULT_PER_PAGE = 50;
    private const int MAX_PER_PAGE = 200;

    /** How long the "a warmup run is in flight" lock is held for. */
    private const int RUN_LOCK_SECONDS = 900;

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
     * Templates a warmup run may use — the catalog, minus what warmup forbids.
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
     * The request only RECORDS the run and queues the fan-out; streaming the
     * selection and dispatching batches happens in {@see SendWarmupCampaignJob} on
     * the low-priority queue, so a long list never occupies a php-fpm worker.
     *
     * Guarded by a cross-process lock rather than a disabled button: two
     * concurrent runs would mail the same seed addresses twice and start their
     * cooldowns from the wrong moment, and the guard has to hold across tabs and
     * app servers. The lock is released by the fan-out job, and expires on its own
     * if a worker dies.
     */
    public function send(SendWarmupEmailsRequest $request, WarmupRecipientService $recipients): JsonResponse
    {
        if ($recipients->available() === 0) {
            return response()->json([
                'ok'      => false,
                'message' => 'The warmup list is empty. Add or import addresses first.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $site = Site::findOrFail($request->integer('site_id'));
        $template = (string) $request->validated('template');
        $limit = $request->recipientLimit();
        $cooldown = $request->cooldownDays();

        // Counted BEFORE the lock so an unsatisfiable run never blocks a valid
        // one. A cooldown that leaves nobody eligible is an ordinary outcome, not
        // a validation error — the admin needs to be told, not corrected.
        $recipientCount = $recipients->count($limit, $cooldown);

        if ($recipientCount === 0) {
            return response()->json([
                'ok'      => false,
                'message' => $cooldown === null
                    ? 'No addresses are available to send to.'
                    : sprintf(
                        'Every address on the list has been contacted within the last %d day(s). Lower the cooldown or add more addresses.',
                        $cooldown,
                    ),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $lock = Cache::lock(SendWarmupCampaignJob::runLockKey(), self::RUN_LOCK_SECONDS);

        if (! $lock->get()) {
            return response()->json([
                'ok'      => false,
                'message' => 'A warmup run is already in progress. Wait for it to finish before starting another.',
            ], Response::HTTP_CONFLICT);
        }

        $send = WarmupSend::create([
            'site_id'         => $site->id,
            'user_id'         => $request->user()?->id,
            'template'        => $template,
            'requested_count' => $limit,
            'cooldown_days'   => $cooldown,
        ]);

        // The owner token travels with the job so only that job can release this
        // exact lock — a slower earlier run can never free a newer one's.
        SendWarmupCampaignJob::dispatch($send->id, $site->id, $template, $limit, $lock->owner(), $cooldown);

        Log::info('Warmup run queued', [
            'warmup_send_id' => $send->id,
            'site_id'        => $site->id,
            'template'       => $template,
            'scope'          => $limit === null ? 'all' : $limit,
            'cooldown_days'  => $cooldown,
            'recipients'     => $recipientCount,
            'admin_id'       => $request->user()?->id,
        ]);

        return response()->json([
            'ok'         => true,
            'send_id'    => $send->id,
            'recipients' => $recipientCount,
            'message'    => sprintf(
                'Warmup queued: %s template for %s to %s.',
                $this->templates->label($template),
                $site->name,
                $limit === null
                    ? "all {$recipientCount} address(es)"
                    : sprintf(
                        '%d most recently added address(es)%s',
                        $recipientCount,
                        $cooldown === null
                            ? ''
                            : " not contacted in the last {$cooldown} day(s)",
                    ),
            ),
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Who a run with these settings would reach right now.
     *
     * Uses the SAME {@see WarmupRecipientService} the send uses, so the number the
     * admin sees before clicking is the number that gets mailed. This is the same
     * "preview the audience" contract as `schedules/{schedule}/recipients` and
     * `newsletter-phones/recipients`.
     *
     * It also carries the cooldown bounds, so the admin's number input takes its
     * min/max from the server rather than hard-coding them in two places.
     */
    public function recipients(Request $request, WarmupRecipientService $recipients): JsonResponse
    {
        $limit = $this->clampedCount($request);
        $cooldown = $this->clampedCooldown($request);

        $total = $recipients->available();
        $eligible = $recipients->eligible($cooldown);

        return response()->json([
            'data' => [
                'total'         => $total,
                'eligible'      => $eligible,
                'recipients'    => $limit === null ? $eligible : min($eligible, $limit),
                'count'         => $limit,
                'cooldown_days' => $cooldown,
                'min_cooldown_days' => WarmupSend::MIN_COOLDOWN_DAYS,
                'max_cooldown_days' => WarmupSend::MAX_COOLDOWN_DAYS,
                'default_cooldown_days' => $this->defaultCooldownDays(),
            ],
        ]);
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
        return WarmupSendRecipient::query()
            ->search($request->query('search'))
            ->forSite($request->query('site_id'))
            ->forTemplate($request->query('template'))
            ->withStatus($request->query('status'));
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

    private function defaultCooldownDays(): int
    {
        $default = (int) config('warmup.default_cooldown_days', WarmupSend::MIN_COOLDOWN_DAYS);

        return min(
            WarmupSend::MAX_COOLDOWN_DAYS,
            max(WarmupSend::MIN_COOLDOWN_DAYS, $default),
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
}
