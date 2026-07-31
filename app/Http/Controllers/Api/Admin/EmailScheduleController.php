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
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin CRUD for scheduled promotion campaigns, plus an on-demand "run now"
 * that queues the campaign immediately (handy for verifying a schedule).
 */
class EmailScheduleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return EmailScheduleResource::collection(
            EmailSchedule::with(['site', 'sendgridKey'])->latest()->paginate(50),
        );
    }

    public function store(StoreEmailScheduleRequest $request): JsonResponse
    {
        $schedule = EmailSchedule::create($request->validated());

        return (new EmailScheduleResource($schedule->load(['site', 'sendgridKey'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateEmailScheduleRequest $request, EmailSchedule $schedule): EmailScheduleResource
    {
        $schedule->update($request->validated());

        return new EmailScheduleResource($schedule->load(['site', 'sendgridKey']));
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

    /** Queue this campaign immediately, regardless of its cadence. */
    public function run(EmailSchedule $schedule): JsonResponse
    {
        SendScheduledPromotionJob::dispatch($schedule->id);
        $schedule->forceFill(['last_run_at' => now()])->save();

        return response()->json([
            'ok'      => true,
            'message' => 'Campaign queued for ' . ($schedule->site?->name ?? 'the selected site') . '.',
        ]);
    }
}
