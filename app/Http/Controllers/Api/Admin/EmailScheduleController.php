<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEmailScheduleRequest;
use App\Http\Requests\Admin\UpdateEmailScheduleRequest;
use App\Http\Resources\EmailScheduleResource;
use App\Jobs\SendScheduledPromotionJob;
use App\Models\EmailSchedule;
use App\Models\Newsletter;
use App\Services\ScheduleRecipientService;
use App\Support\CsvExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Admin CRUD for scheduled promotion campaigns, plus an on-demand "run now"
 * that queues the campaign immediately (handy for verifying a schedule).
 */
class EmailScheduleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return EmailScheduleResource::collection(
            EmailSchedule::with(['site', 'sendgridKey', 'mailgunKey'])->latest()->paginate(50),
        );
    }

    public function store(StoreEmailScheduleRequest $request): JsonResponse
    {
        $schedule = EmailSchedule::create($request->validated());

        return (new EmailScheduleResource($schedule->load(['site', 'sendgridKey', 'mailgunKey'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateEmailScheduleRequest $request, EmailSchedule $schedule): EmailScheduleResource
    {
        $schedule->update($request->validated());

        return new EmailScheduleResource($schedule->load(['site', 'sendgridKey', 'mailgunKey']));
    }

    public function destroy(EmailSchedule $schedule): JsonResponse
    {
        $schedule->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Who would receive this campaign if it ran right now.
     *
     * Resolved by {@see ScheduleRecipientService} — the SAME query the send
     * itself uses — so the number shown here is the number that will be mailed.
     * Always reads live data (no caching). Returns the exact total plus a small
     * sample for the table; the full list is available via {@see exportRecipients()}.
     */
    public function recipients(Request $request, EmailSchedule $schedule, ScheduleRecipientService $recipients): JsonResponse
    {
        // Bounded so a preview can never pull an unbounded list into memory.
        $sampleSize = min(max($request->integer('sample', 25), 1), 200);

        return response()->json([
            'data' => [
                'count'       => $recipients->count($schedule),
                'sample_size' => $sampleSize,
                'sample'      => $recipients->sample($schedule, $sampleSize)
                    ->map(fn (Newsletter $n): array => [
                        'email'      => $n->email,
                        'created_at' => $n->created_at?->toDateTimeString(),
                    ])->all(),
                'generated_at' => now()->toDateTimeString(),
            ],
        ]);
    }

    /**
     * Stream the full recipient list as CSV — email + created_at only.
     *
     * Same resolver as the preview and the send, streamed row by row so a
     * 50k-recipient export never materialises in memory.
     */
    public function exportRecipients(EmailSchedule $schedule, ScheduleRecipientService $recipients): StreamedResponse
    {
        $filename = 'schedule-' . $schedule->id . '-recipients-' . now()->format('Ymd-His') . '.csv';

        Log::info('Schedule recipients exported', [
            'schedule_id' => $schedule->id,
            'site_id'     => $schedule->site_id,
            'admin_id'    => auth()->id(),
        ]);

        return CsvExport::download($filename, ['email', 'created_at'], $recipients->exportRows($schedule));
    }

    /**
     * Queue this campaign immediately, regardless of its cadence.
     *
     * Guarded three ways, because this button can mean 50k emails:
     *  - the conditions the fan-out would silently return on (paused schedule,
     *    promotion template switched off) are reported here instead, so the
     *    admin is never told "queued" when nothing will be sent;
     *  - a lock keeps one campaign in flight per schedule, so a double-click or
     *    two admins on the same screen cannot fan out the same audience twice.
     *    The fan-out releases it when it finishes;
     *  - `last_run_at` is stamped, so the scheduler will not also fire this
     *    schedule during the same minute.
     */
    public function run(EmailSchedule $schedule): JsonResponse
    {
        if (! $schedule->active) {
            return $this->refuse('This schedule is paused. Activate it before running the campaign.');
        }

        $site = $schedule->site;

        if ($site === null) {
            return $this->refuse('This schedule has no site attached.');
        }

        if (! $site->promotionEmailOrDefault()->active) {
            return $this->refuse("The promotion email for {$site->name} is switched off, so nothing would be sent.");
        }

        // Held for the fan-out's lifetime; the job releases it on completion or
        // failure, and the TTL covers a worker that dies outright.
        $lock = Cache::lock(
            SendScheduledPromotionJob::runLockKey($schedule->id),
            (int) config('promotions.fan_out_timeout', 900),
        );

        if (! $lock->get()) {
            return response()->json([
                'ok'      => false,
                'message' => 'This campaign is already running. Wait for it to finish before starting another.',
            ], Response::HTTP_CONFLICT);
        }

        // Stamp first: if the dispatch throws, the scheduler must not pick the
        // same minute up and send a second time.
        $schedule->forceFill(['last_run_at' => now()])->save();

        try {
            SendScheduledPromotionJob::dispatch($schedule->id, $lock->owner());
        } catch (Throwable $e) {
            $lock->release();

            Log::error('Manual promotion run could not be queued', [
                'schedule_id' => $schedule->id,
                'admin_id'    => auth()->id(),
                'error'       => $e->getMessage(),
            ]);

            return $this->refuse('The campaign could not be queued. Check the queue connection and try again.');
        }

        Log::info('Promotion campaign queued manually', [
            'schedule_id' => $schedule->id,
            'site_id'     => $schedule->site_id,
            'admin_id'    => auth()->id(),
        ]);

        return response()->json([
            'ok'      => true,
            'message' => 'Campaign queued for ' . $site->name . '.',
        ]);
    }

    /** A refused run: 422 with the reason, and nothing queued. */
    private function refuse(string $message): JsonResponse
    {
        return response()->json(
            ['ok' => false, 'message' => $message],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
