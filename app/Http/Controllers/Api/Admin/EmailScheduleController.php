<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEmailScheduleRequest;
use App\Http\Requests\Admin\UpdateEmailScheduleRequest;
use App\Http\Resources\EmailScheduleResource;
use App\Jobs\SendScheduledPromotionJob;
use App\Models\EmailSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Admin CRUD for scheduled promotion campaigns, plus an on-demand "run now"
 * that queues the campaign immediately (handy for verifying a schedule).
 */
class EmailScheduleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return EmailScheduleResource::collection(
            EmailSchedule::with('site')->latest()->paginate(50),
        );
    }

    public function store(StoreEmailScheduleRequest $request): JsonResponse
    {
        $schedule = EmailSchedule::create($request->validated());

        return (new EmailScheduleResource($schedule->load('site')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateEmailScheduleRequest $request, EmailSchedule $schedule): EmailScheduleResource
    {
        $schedule->update($request->validated());

        return new EmailScheduleResource($schedule->load('site'));
    }

    public function destroy(EmailSchedule $schedule): JsonResponse
    {
        $schedule->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
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
