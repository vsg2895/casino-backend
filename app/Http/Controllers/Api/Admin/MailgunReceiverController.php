<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportMailgunReceiversRequest;
use App\Http\Requests\Admin\StoreMailgunReceiverRequest;
use App\Http\Requests\Admin\UpdateMailgunReceiverRequest;
use App\Http\Resources\MailgunReceiverResource;
use App\Jobs\ImportMailgunReceiversJob;
use App\Models\MailgunReceiver;
use App\Models\MailgunReceiverImport;
use App\Models\MailgunSuppression;
use App\Services\MailgunReceiverSendStateResetter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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
            $this->filtered($request)->latest('id')->paginate($perPage),
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
            'source'              => MailgunReceiver::SOURCE_MANUAL,
            'consent_recorded_at' => now(),
            // Every receiver is active. There is no half-member of this list:
            // an address is either on it and mailed, or removed.
            'is_active'           => true,
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
    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:delete'],
            'ids'    => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*'  => ['integer'],
        ]);

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
    public function resetSends(Request $request, MailgunReceiverSendStateResetter $resetter): JsonResponse
    {
        $validated = $request->validate([
            'clear_errors' => ['sometimes', 'boolean'],
        ]);

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
            'user_id'        => $request->user()?->id,
            'filename'       => $file->getClientOriginalName(),
            'path'           => $path,
            'consent_source' => (string) $request->validated('consent_source'),
            'status'         => MailgunReceiverImport::STATUS_QUEUED,
        ]);

        ImportMailgunReceiversJob::dispatch($import->id);

        return response()->json(['data' => $import], Response::HTTP_ACCEPTED);
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
    private function filtered(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = MailgunReceiver::query()->search($request->query('search'));

        // No `active` filter: every receiver is active, so it would only ever
        // return everything or nothing.
        if ($request->boolean('unsubscribed')) {
            $query->whereNotNull('unsubscribed_at');
        }

        if (in_array($request->query('source'), MailgunReceiver::SOURCES, true)) {
            $query->where('source', $request->query('source'));
        }

        // "Suppressed" is a property of the shared list, not of the row, so it is
        // expressed as an EXISTS rather than a column filter.
        if ($request->boolean('suppressed')) {
            $query->whereExists(static function ($sub): void {
                $sub->selectRaw('1')
                    ->from('mailgun_suppressions')
                    ->whereColumn('mailgun_suppressions.email', 'mailgun_receivers.email');
            });
        }

        return $query;
    }
}
