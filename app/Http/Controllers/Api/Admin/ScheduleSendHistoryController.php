<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduleSendHistoryResource;
use App\Models\EmailSchedule;
use App\Models\ScheduleSendHistory;
use App\Support\DayWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Email History → Schedules: every address a schedule sent to, skipped or
 * failed on, with the list it came from and the email config that carried it.
 *
 * Simple-paginated with a dedicated COUNT, sharing one {@see filtered()} so the
 * listing and the badge never disagree — the contract every admin history
 * follows. Filters are lenient: an unknown value filters nothing rather than
 * 422-ing a log view.
 */
class ScheduleSendHistoryController extends Controller
{
    private const int DEFAULT_PER_PAGE = 50;

    private const int MAX_PER_PAGE = 200;

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page') ?: self::DEFAULT_PER_PAGE, 1), self::MAX_PER_PAGE);

        return ScheduleSendHistoryResource::collection(
            $this->filtered($request)
                ->with('site:id,name')
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->simplePaginate($perPage)
                ->withQueryString(),
        );
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->filtered($request)->count()]);
    }

    /** Delete one row — the same per-row delete every other history offers. */
    public function destroy(ScheduleSendHistory $scheduleSendHistory): JsonResponse
    {
        $scheduleSendHistory->delete();

        return response()->json(null, 204);
    }

    /**
     * The schedules that appear in the history, for the Schedule filter —
     * including deleted ones, by the name they were sent under.
     */
    public function schedules(): JsonResponse
    {
        $rows = ScheduleSendHistory::query()
            ->whereNotNull('email_schedule_id')
            ->select('email_schedule_id')
            ->selectRaw('MAX(schedule_name) as schedule_name')
            ->groupBy('email_schedule_id')
            ->orderBy('email_schedule_id')
            ->get();

        return response()->json(['data' => $rows->map(static fn (ScheduleSendHistory $row): array => [
            'id'   => (int) $row->email_schedule_id,
            'name' => $row->schedule_name ?: 'Schedule #' . $row->email_schedule_id,
        ])->all()]);
    }

    /** @return Builder<ScheduleSendHistory> */
    private function filtered(Request $request): Builder
    {
        $tz = $request->query('tz');
        $tz = (is_string($tz) && DayWindow::isValidTimezone($tz)) ? $tz : null;

        return ScheduleSendHistory::query()
            ->when($request->integer('schedule_id') ?: null, fn (Builder $q, int $id) => $q->where('email_schedule_id', $id))
            ->when($request->integer('site_id') ?: null, fn (Builder $q, int $id) => $q->where('site_id', $id))
            ->when(
                $this->oneOf($request, 'audience', EmailSchedule::AUDIENCES),
                fn (Builder $q, string $audience) => $q->where('audience', $audience),
            )
            ->when(
                $this->oneOf($request, 'provider', EmailSchedule::PROVIDERS),
                fn (Builder $q, string $provider) => $q->where('provider', $provider),
            )
            ->when(
                $this->oneOf($request, 'status', ScheduleSendHistory::STATUSES),
                fn (Builder $q, string $status) => $q->where('status', $status),
            )
            ->when(
                DayWindow::startUtc($this->day($request->query('from')), $tz),
                fn (Builder $q, $from) => $q->where('sent_at', '>=', $from),
            )
            ->when(
                DayWindow::endUtc($this->day($request->query('to')), $tz),
                fn (Builder $q, $to) => $q->where('sent_at', '<=', $to),
            )
            ->when($this->searchTerm($request), fn (Builder $q, string $term) => $q->where('email', 'like', $term . '%'));
    }

    /** @param  list<string>  $allowed */
    private function oneOf(Request $request, string $key, array $allowed): ?string
    {
        $value = (string) $request->query($key);

        return in_array($value, $allowed, true) ? $value : null;
    }

    private function day(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Prefix search with LIKE wildcards escaped, so input cannot become a %..% scan. */
    private function searchTerm(Request $request): ?string
    {
        $term = trim((string) $request->query('search'));

        return $term === '' ? null : str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
