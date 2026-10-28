<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkMailgunReceiversRequest;
use App\Http\Requests\Admin\ImportMailgunReceiversRequest;
use App\Http\Requests\Admin\ResetMailgunReceiverSendsRequest;
use App\Http\Requests\Admin\StoreMailgunReceiverRequest;
use App\Http\Requests\Admin\UpdateMailgunReceiverRequest;
use App\Http\Resources\MailgunReceiverResource;
use App\Jobs\ImportMailgunReceiversJob;
use App\Models\MailgunReceiver;
use App\Models\MailgunReceiverImport;
use App\Services\MailgunReceiverSendStateResetter;
use App\Support\DayWindow;
use App\Support\Mail\ReceiverSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Admin CRUD for the Mailgun receiver list.
 *
 * Shaped after {@see NewsletterController} — paginated listing, a dedicated
 * COUNT endpoint so the badge never rides on the paginated query, a queued
 * import polled through its own row. Nothing here reads or writes the newsletter
 * tables.
 */
class MailgunReceiverController extends Controller
{
    private const int DEFAULT_PER_PAGE = 50;

    private const int MAX_PER_PAGE = 200;

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            max($request->integer('per_page') ?: self::DEFAULT_PER_PAGE, 1),
            self::MAX_PER_PAGE,
        );

        return MailgunReceiverResource::collection(
            $this->filtered($request)->latest('id')->simplePaginate($perPage),
        );
    }

    /** Total matching the current filters, as a dedicated COUNT. */
    public function count(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->filtered($request)->count()]);
    }

    public function store(StoreMailgunReceiverRequest $request): JsonResponse
    {
        $receiver = MailgunReceiver::create([
            ...$request->validated(),
            'source' => MailgunReceiver::SOURCE_MANUAL,
            'consent_recorded_at' => now(),
            // Every receiver is active. There is no half-member of this list:
            // an address is either on it and mailed, or removed.
            'is_active' => true,
        ]);

        return (new MailgunReceiverResource($receiver))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateMailgunReceiverRequest $request,
        MailgunReceiver $mailgunReceiver,
    ): MailgunReceiverResource {
        $mailgunReceiver->update([...$request->validated(), 'is_active' => true]);

        return new MailgunReceiverResource($mailgunReceiver);
    }

    /**
     * Soft delete. The row keeps its place in the unique index, so re-importing
     * the same address later is reported as a duplicate rather than silently
     * resurrecting someone who was removed on purpose.
     */
    public function destroy(MailgunReceiver $mailgunReceiver): JsonResponse
    {
        $mailgunReceiver->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Bulk delete.
     *
     * One statement rather than a loop of model saves: the admin can select
     * thousands of rows, and N queries would time out the request.
     *
     * Activate/deactivate used to live here and no longer do. Every receiver is
     * sendable now, so "deactivate" would have left a row on the list that looks
     * present but is never mailed — a state with no honest meaning. Removing an
     * address from the audience means deleting it; opting a person out means
     * their unsubscribe, which no admin action may forge.
     */
    public function bulk(BulkMailgunReceiversRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $affected = MailgunReceiver::whereIn('id', $validated['ids'])->delete();

        return response()->json(['affected' => $affected]);
    }

    /**
     * Clear "Last sent" and "Sent" on every receiver.
     *
     * Deliberately takes no id list. This is the whole-list reset the artisan
     * command performs, exposed for the button in Mailgun Credentials; both go
     * through {@see MailgunReceiverSendStateResetter}, so the panel and the CLI
     * cannot drift into resetting different things.
     *
     * Clearing `last_sent_at` drops the cooldown filter for everyone, so the next
     * campaign can mail the whole list. The confirmation lives in the admin
     * dialog — by the time this runs, the answer was yes.
     */
    public function resetSends(ResetMailgunReceiverSendsRequest $request, MailgunReceiverSendStateResetter $resetter): JsonResponse
    {
        $validated = $request->validated();

        $affected = $resetter->reset((bool) ($validated['clear_errors'] ?? false));

        return response()->json(['affected' => $affected]);
    }

    /**
     * Stage an uploaded spreadsheet and queue the import.
     *
     * The file is written to the local disk and only its id is queued, so the
     * queue payload never carries megabytes of spreadsheet. The admin polls
     * {@see importStatus()} until `finished_at`.
     */
    public function import(ImportMailgunReceiversRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('mailgun-receiver-imports', 'local');

        $import = MailgunReceiverImport::create([
            'user_id' => $request->user()?->id,
            'filename' => $file->getClientOriginalName(),
            // Stamped on every row the job writes. Stored here rather than
            // passed to the job because the upload and the processing are two
            // requests, and the choice belongs to the upload.
            'source_label' => $request->validated()['source_label'] ?? ReceiverSource::DEFAULT,
            'path' => $path,
            'status' => MailgunReceiverImport::STATUS_QUEUED,
        ]);

        ImportMailgunReceiversJob::dispatch($import->id);

        return response()->json(['data' => $import], Response::HTTP_ACCEPTED);
    }

    /**
     * The two sources, each with how many addresses carry it.
     *
     * Drives the pickers: the Receivers filter and the "Send to sources"
     * control in Email Config. The VALUES come from the enum — a closed set —
     * while the counts come from the table, so the control can say how much is
     * behind each option before it is chosen.
     */
    public function sources(): JsonResponse
    {
        $counts = MailgunReceiver::query()
            ->selectRaw('source_label, COUNT(*) as total')
            ->groupBy('source_label')
            ->pluck('total', 'source_label');

        $sources = array_map(
            static fn (ReceiverSource $source): array => [
                'value' => $source->value,
                'label' => $source->label(),
                // Present even at zero: an option that disappears when its list
                // empties is an option an operator cannot plan with.
                'total' => (int) ($counts[$source->value] ?? 0),
            ],
            ReceiverSource::cases(),
        );

        return response()->json(['data' => ['sources' => $sources]]);
    }

    /** Poll target for a queued import. */
    public function importStatus(MailgunReceiverImport $mailgunReceiverImport): JsonResponse
    {
        return response()->json(['data' => $mailgunReceiverImport]);
    }

    /**
     * The filter set, shared by the listing and the count so the two can never
     * disagree about how many rows match.
     */
    private function filtered(Request $request): Builder
    {
        $query = MailgunReceiver::query()->search($request->query('search'));

        // No `active` filter: every receiver is active, so it would only ever
        // return everything or nothing.

        if (in_array($request->query('source'), MailgunReceiver::SOURCES, true)) {
            $query->where('source', $request->query('source'));
        }

        // Whose list an address came from. An unrecognised value is ignored
        // rather than matched, so a stale link shows everything instead of
        // nothing.
        $source = ReceiverSource::parse($request->query('source_label'));

        if ($source !== null) {
            $query->where('source_label', $source->value);
        }

        // Has this address been mailed at all?
        //
        // Keyed on `last_sent_at` and NOT on `sent_count`, so this filter and the
        // date range below can never disagree: both read the one column, and the
        // reset action clears them together. `sent_count` counts sends, which is
        // the same answer today and one schema change away from not being.
        $sent = $request->query('sent');

        if ($sent === 'yes') {
            $query->whereNotNull('last_sent_at');
        } elseif ($sent === 'no') {
            $query->whereNull('last_sent_at');
        }

        // Last-sent date range, inclusive of both days.
        //
        // Compared as DATETIMES against day boundaries rather than with
        // whereDate(): wrapping the column in DATE() would make
        // `mailgun_receivers_last_sent_at_index` unusable and turn every filtered
        // page into a full scan of the list.
        /*
         * Resolved in the OPERATOR'S zone, then compared against the UTC
         * column. Without the zone a calendar day was read as UTC midnight, so
         * an operator east of Greenwich lost the first hours of their own day
         * out of every window while the table — which renders `last_sent_at` in
         * local time — still showed those rows. Absent `tz` falls back to UTC,
         * exactly as before. See App\Support\DayWindow.
         */
        $tz = $request->query('tz');
        $tz = (is_string($tz) && DayWindow::isValidTimezone($tz)) ? $tz : null;

        if ($from = DayWindow::startUtc($this->dayString($request->query('last_sent_from')), $tz)) {
            $query->where('last_sent_at', '>=', $from);
        }

        if ($to = DayWindow::endUtc($this->dayString($request->query('last_sent_to')), $tz)) {
            $query->where('last_sent_at', '<=', $to);
        }

        return $query;
    }

    /**
     * A filter date, or null when the value is absent or unparseable.
     *
     * Lenient on purpose, matching how `source` is handled above: a junk value in
     * a query string filters nothing rather than 422-ing a listing. The admin
     * sends ISO dates from its own picker, so a bad one means a hand-edited URL.
     */
    private function dayString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
