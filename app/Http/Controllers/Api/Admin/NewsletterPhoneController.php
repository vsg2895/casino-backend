<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkNewsletterPhoneIdsRequest;
use App\Http\Requests\Admin\ImportNewsletterPhonesRequest;
use App\Http\Requests\Admin\SendBulkSmsRequest;
use App\Http\Requests\Admin\StoreNewsletterPhoneRequest;
use App\Http\Requests\Admin\UpdateNewsletterPhoneRequest;
use App\Http\Resources\NewsletterPhoneResource;
use App\Http\Resources\PhoneSmsHistoryResource;
use App\Jobs\ImportPhoneNewslettersJob;
use App\Jobs\SendBulkSmsJob;
use App\Models\NewsletterBasedOnPhone;
use App\Models\PhoneNewsletterImport;
use App\Models\PhoneSmsHistory;
use App\Models\TwilioConfig;
use App\Services\PhoneRecipientService;
use App\Support\CsvExport;
use App\Support\Phone\PhoneAudienceFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin management of the STANDALONE phone-newsletter list, plus its spreadsheet
 * import and its bulk SMS send.
 *
 * Every query in this controller reads `newsletters_based_on_phone` (or its own
 * import/history tables). Nothing here touches `newsletters`, `clients`, or any
 * relationship between them — the feature is independent of the email side by
 * construction, not by convention.
 *
 * Structure follows the newsletter and warmup modules so the admin panel behaves
 * consistently: a shared {@see filtered()} builder behind both the listing and the
 * dedicated COUNT, the same page-size clamp, the same bulk-delete shape, and the
 * same "stage the upload, process it on the queue, poll for progress" import.
 *
 * The bulk send is the one place that departs from the email equivalent: it runs
 * behind a cross-process lock, because a double-clicked button would otherwise
 * queue two identical runs and an SMS, unlike an email, is billed per message and
 * cannot be recalled.
 */
class NewsletterPhoneController extends Controller
{
    /** Page size bounds for the admin listings. */
    private const int DEFAULT_PER_PAGE = 50;
    private const int MAX_PER_PAGE = 200;

    /** Columns the listing may be sorted by. A whitelist, not user input. */
    private const array SORTABLE = ['created_at', 'phone', 'opted_out'];

    /** How long the "a bulk run is in flight" lock is held for. */
    private const int RUN_LOCK_SECONDS = 900;

    /** Recipients shown in the pre-send preview. */
    private const int PREVIEW_LIMIT = 25;

    /** Rows removed per statement by {@see destroyAll()}. */
    private const int DELETE_CHUNK = 1000;

    // ── Listing ──────────────────────────────────────────────────────────────

    public function index(Request $request): AnonymousResourceCollection
    {
        // Driven by the table's rows-per-page control, clamped so a crafted
        // request cannot ask for a 50k-row page.
        $perPage = min(
            max($request->integer('per_page') ?: self::DEFAULT_PER_PAGE, 1),
            self::MAX_PER_PAGE,
        );

        [$sortColumn, $sortDirection] = $this->sort($request);

        $query = $this->filtered($request)->orderBy($sortColumn, $sortDirection);

        // Tiebreaker, and not optional: a bulk import writes thousands of rows
        // with an identical created_at, and MySQL does not guarantee a stable
        // order among ties. Without this, paging through an imported list repeats
        // some rows and skips others.
        if ($sortColumn !== 'id') {
            $query->orderBy('id', $sortDirection);
        }

        return NewsletterPhoneResource::collection($query->paginate($perPage));
    }

    /**
     * Total matching the current filters, as a dedicated COUNT.
     *
     * Built from {@see filtered()} — the same conditions the listing uses — with
     * no ordering and no column selection, so the paginated query never carries
     * the weight of counting.
     */
    public function count(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->filtered($request)->count()]);
    }

    /**
     * THE single definition of "which numbers is the admin looking at", shared by
     * the listing and the count so the two can never disagree.
     *
     * Date and search semantics come from {@see PhoneRecipientService::applyFilters()},
     * the same code the send audience uses — so a filter selection means one thing
     * across the whole feature.
     *
     * @return Builder<NewsletterBasedOnPhone>
     */
    private function filtered(Request $request): Builder
    {
        $query = app(PhoneRecipientService::class)->applyFilters(
            NewsletterBasedOnPhone::query(),
            PhoneAudienceFilter::fromRequest($request),
        );

        // Tri-state, unlike the audience (which always excludes opt-outs): the
        // admin must be able to look at opted-out numbers, list them, and see how
        // many there are.
        $optedOut = $this->optedOutFilter($request);

        return $query->when($optedOut !== null, fn (Builder $q) => $q->where('opted_out', $optedOut));
    }

    /**
     * The ?opted_out filter as a tri-state: true, false, or null for "all".
     *
     * `$request->boolean()` is unusable here — it returns false both for
     * "opted_out=0" and for an absent parameter, which would silently turn "show
     * everyone" into "show only subscribed". Presence has to be checked before the
     * value is interpreted. Same reasoning as the newsletter list's ?verified.
     */
    private function optedOutFilter(Request $request): ?bool
    {
        $value = $request->query('opted_out');

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The requested sort, reduced to a whitelisted column and direction.
     *
     * @return array{0: string, 1: string}
     */
    private function sort(Request $request): array
    {
        $column = (string) $request->query('sort_by', 'created_at');
        $direction = strtolower((string) $request->query('sort_dir', 'desc'));

        return [
            in_array($column, self::SORTABLE, true) ? $column : 'created_at',
            $direction === 'asc' ? 'asc' : 'desc',
        ];
    }

    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function store(StoreNewsletterPhoneRequest $request): JsonResponse
    {
        $phone = NewsletterBasedOnPhone::create($request->validated());

        return (new NewsletterPhoneResource($phone))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateNewsletterPhoneRequest $request,
        NewsletterBasedOnPhone $newsletterPhone,
    ): NewsletterPhoneResource {
        // payload() rather than validated(): it keeps opted_out_at consistent with
        // opted_out, which are one fact rather than two fields.
        $newsletterPhone->update($request->payload());

        return new NewsletterPhoneResource($newsletterPhone);
    }

    /** Hard delete — this table has no soft deletes and needs no trash view. */
    public function destroy(NewsletterBasedOnPhone $newsletterPhone): JsonResponse
    {
        $newsletterPhone->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function bulkDestroy(BulkNewsletterPhoneIdsRequest $request): JsonResponse
    {
        $deleted = NewsletterBasedOnPhone::query()->whereIn('id', $request->ids())->delete();

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * Delete every number matching the CURRENT filters.
     *
     * Scoped to {@see filtered()} rather than truncating the table: the admin is
     * looking at a filtered view, and "delete all" has to mean what is on screen.
     *
     * Deleted in chunks, not as one statement. These are HARD deletes (the table
     * has no soft deletes), and a single `DELETE ... WHERE` across 50k+ rows holds
     * row locks and grows the InnoDB undo log for the whole duration, stalling
     * concurrent imports and sends. Chunking keeps each transaction short — the
     * same reason the `newsletters:delete` command chunks.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $deleted = 0;

        do {
            // A fresh builder each pass: the previous chunk's rows are gone, so
            // the same filters now match the next batch.
            $ids = $this->filtered($request)->limit(self::DELETE_CHUNK)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += NewsletterBasedOnPhone::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::DELETE_CHUNK);

        Log::info('Phone newsletter numbers bulk-deleted', [
            'deleted'  => $deleted,
            'admin_id' => $request->user()?->id,
        ]);

        return response()->json(['deleted' => $deleted]);
    }

    // ── Import ───────────────────────────────────────────────────────────────

    /**
     * Queue a bulk import of numbers from an uploaded .xlsx / .csv with a "Phone"
     * column.
     *
     * The request only STAGES the file and returns; parsing and writing happen in
     * {@see ImportPhoneNewslettersJob} on the high-priority queue. A large list
     * must never be processed inside an HTTP request, where it would burn a
     * php-fpm worker and eventually hit max_execution_time.
     *
     * Responds 202 with the import record; poll {@see importStatus()} for progress
     * and the final counts.
     */
    public function import(ImportNewsletterPhonesRequest $request): JsonResponse
    {
        $file = $request->file('file');

        $import = PhoneNewsletterImport::create([
            'user_id'  => $request->user()?->id,
            'filename' => $file->getClientOriginalName(),
            // Staged on the local disk; the job reads it and deletes it. The
            // worker must therefore share this filesystem with the web process.
            'path'     => $file->store('phone-imports', 'local'),
            'status'   => PhoneNewsletterImport::STATUS_QUEUED,
        ]);

        ImportPhoneNewslettersJob::dispatch($import->id);

        // Under a synchronous queue the job has already run by now, so report
        // whatever state it reached rather than a stale "queued".
        return $this->importPayload($import->refresh(), Response::HTTP_ACCEPTED);
    }

    /** Progress + outcome of a queued import, polled by the admin panel. */
    public function importStatus(PhoneNewsletterImport $import): JsonResponse
    {
        return $this->importPayload($import);
    }

    /** The flat import payload both endpoints answer with. */
    private function importPayload(PhoneNewsletterImport $import, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'import_id' => $import->id,
            'status'    => $import->status,
            'finished'  => $import->isFinished(),
            'imported'  => $import->imported,
            'skipped'   => $import->skipped,
            'invalid'   => $import->invalid,
            'total'     => $import->total,
            'error'     => $import->error,
            'message'   => $import->summary(),
        ], $status);
    }

    /** CSV of the numbers matching the current filters. */
    public function export(Request $request): StreamedResponse
    {
        [$sortColumn, $sortDirection] = $this->sort($request);

        $rows = $this->filtered($request)
            ->orderBy($sortColumn, $sortDirection)
            ->cursor()
            ->map(fn (NewsletterBasedOnPhone $row) => [
                $row->phone,
                $row->opted_out ? 'yes' : 'no',
                $row->created_at?->format('d/m/Y, g:i A') ?? '',
            ]);

        return CsvExport::download(
            'phone-newsletter.csv',
            ['Phone number', 'Opted out', 'Created at'],
            $rows,
        );
    }

    // ── Bulk SMS ─────────────────────────────────────────────────────────────

    /**
     * How many numbers a send with the current filters would reach, and a sample
     * of who they are.
     *
     * Resolved by {@see PhoneRecipientService} — the same code the send itself
     * uses — so this is a prediction rather than an estimate. Note the count here
     * is smaller than the listing's total whenever opted-out numbers match the
     * filters: they are excluded from any send.
     */
    public function recipients(Request $request, PhoneRecipientService $recipients): JsonResponse
    {
        $filter = PhoneAudienceFilter::fromRequest($request);

        $sample = $recipients->sample($filter, self::PREVIEW_LIMIT)
            ->map(fn (NewsletterBasedOnPhone $row): array => [
                'phone'      => $row->phone,
                'created_at' => $row->created_at,
            ])
            ->all();

        return response()->json([
            'total'       => $recipients->count($filter),
            'sample'      => $sample,
            'filters'     => $filter->describe(),
            'sample_size' => count($sample),
        ]);
    }

    /** CSV of exactly who a send with the current filters would reach. */
    public function exportRecipients(Request $request, PhoneRecipientService $recipients): StreamedResponse
    {
        $filter = PhoneAudienceFilter::fromRequest($request);

        return CsvExport::download(
            'sms-recipients.csv',
            ['Phone number', 'Created at'],
            $recipients->exportRows($filter),
        );
    }

    /**
     * Start a bulk SMS run.
     *
     * Returns as soon as the fan-out is queued — the actual sending happens in
     * {@see SendBulkSmsJob} and its batches, on the low-priority queue.
     *
     * Guarded by a cross-process lock rather than a UI-only disabled button. Two
     * concurrent runs would message the same numbers twice, and a duplicate SMS is
     * billed and cannot be recalled, so the guard has to hold across tabs,
     * browsers and app servers. The lock is released by the fan-out job when it
     * finishes, and expires on its own if the worker dies.
     */
    public function send(SendBulkSmsRequest $request, PhoneRecipientService $recipients): JsonResponse
    {
        $config = TwilioConfig::findOrFail($request->integer('twilio_config_id'));

        // Checked before anything is queued, so a misconfigured credential is one
        // clear error rather than a queued run that fails per recipient.
        if (! $config->hasSender()) {
            return response()->json([
                'ok'      => false,
                'message' => "\"{$config->name}\" has no sender configured. Add a Twilio phone number or a Messaging Service SID first.",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $filter = $request->audienceFilter();

        // Resolved before the lock is taken: telling the admin "nobody matches"
        // costs one COUNT and avoids holding a 15-minute lock for a run that has
        // nothing to do.
        $total = $recipients->count($filter);

        if ($total === 0) {
            return response()->json([
                'ok'      => false,
                'message' => 'No numbers match the selected filters, so nothing was queued.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $lock = Cache::lock(SendBulkSmsJob::runLockKey(), self::RUN_LOCK_SECONDS);

        if (! $lock->get()) {
            return response()->json([
                'ok'      => false,
                'message' => 'A bulk SMS run is already in progress. Wait for it to finish before starting another.',
            ], Response::HTTP_CONFLICT);
        }

        // The owner token travels with the job so the job — and only the job — can
        // release this exact lock; a later run's lock can never be released by an
        // earlier, slower one.
        SendBulkSmsJob::dispatch(
            $config->id,
            $request->body(),
            $filter->toArray(),
            $lock->owner(),
        );

        Log::info('Bulk SMS run queued', [
            'twilio_config_id' => $config->id,
            'recipients'       => $total,
            'filters'          => $filter->describe(),
            'admin_id'         => $request->user()?->id,
        ]);

        return response()->json([
            'ok'         => true,
            'recipients' => $total,
            'filters'    => $filter->describe(),
            'message'    => "Queued an SMS to {$total} number(s) ({$filter->describe()}).",
        ], Response::HTTP_ACCEPTED);
    }

    // ── Send history ─────────────────────────────────────────────────────────

    /**
     * The per-recipient result of past sends — what was sent, whether Twilio
     * accepted it, and the exact failure when it did not.
     */
    public function history(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            max($request->integer('per_page') ?: self::DEFAULT_PER_PAGE, 1),
            self::MAX_PER_PAGE,
        );

        $query = $this->filteredHistory($request)
            ->with('twilioConfig')
            ->latest()
            // Same tiebreaker reasoning as the listing: one batch writes many rows
            // with an identical created_at.
            ->orderByDesc('id');

        return PhoneSmsHistoryResource::collection($query->paginate($perPage));
    }

    /** Total history rows matching the current filters, as a dedicated COUNT. */
    public function historyCount(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->filteredHistory($request)->count()]);
    }

    /**
     * Shared by the history listing and its count.
     *
     * @return Builder<PhoneSmsHistory>
     */
    private function filteredHistory(Request $request): Builder
    {
        $status = $request->query('status');

        return PhoneSmsHistory::query()
            ->search($request->query('search'))
            ->when(
                in_array($status, PhoneSmsHistory::STATUSES, true),
                fn (Builder $q) => $q->where('status', $status),
            );
    }
}
