<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendScheduledPromotionJob;
use App\Models\EmailSchedule;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches promotion campaigns for any schedule due at the current minute.
 *
 * Registered in routes/console.php to run every minute via the Laravel
 * scheduler (`php artisan schedule:run`, driven by a single system cron entry).
 * DB-driven schedules can't be declared statically, so this command is the
 * bridge: it reads active schedules and fans due ones out to the queue.
 *
 * Claiming is ATOMIC (see {@see claim()}): the minute is written with a
 * conditional UPDATE and the campaign is dispatched only if this process won
 * the row. Two app servers running cron, or an overlapping scheduler tick, can
 * therefore never dispatch the same campaign twice — a real risk once a
 * campaign means 50k emails.
 */
class DispatchDuePromotionSchedules extends Command
{
    protected $signature = 'promotions:dispatch-due';

    protected $description = 'Queue promotion campaigns for schedules due this minute';

    public function handle(): int
    {
        $now = now();
        // Everything keys off the start of the minute: it is what gets written
        // to last_run_at, and what the claim compares against.
        $minute = $now->copy()->startOfMinute();
        $dispatched = 0;

        EmailSchedule::query()
            ->where('active', true)
            // Narrow in SQL on the (active, time) index instead of loading every
            // active schedule and filtering in PHP. A prefix LIKE keeps both
            // 'HH:MM' and 'HH:MM:SS' storage working and stays index-usable.
            ->where('time', 'like', $now->format('H:i') . '%')
            ->cursor()
            ->each(function (EmailSchedule $schedule) use ($now, $minute, &$dispatched): void {
                if (! $schedule->isDue($now) || ! $this->claim($schedule, $minute)) {
                    return;
                }

                // The SAME lock the admin's "Run now" takes, so the two triggers
                // can never fan out one schedule concurrently. Without it, an
                // admin clicking Run now in the minute the cron fires would send
                // the whole audience twice: the 24h dedup only suppresses a
                // re-send once the first delivery is written to history, which
                // has not happened yet while both fan-outs are in flight.
                $lock = Cache::lock(
                    SendScheduledPromotionJob::runLockKey($schedule->id),
                    (int) config('promotions.fan_out_timeout', 900),
                );

                if (! $lock->get()) {
                    Log::warning('Promotion schedule skipped: a campaign for it is already running', [
                        'schedule_id' => $schedule->id,
                        'minute'      => $minute->toDateTimeString(),
                    ]);

                    return;
                }

                try {
                    // The job releases the lock when the fan-out ends.
                    SendScheduledPromotionJob::dispatch($schedule->id, $lock->owner());
                    $dispatched++;
                } catch (Throwable $e) {
                    $lock->release();

                    // The minute is already claimed, so this campaign is skipped
                    // rather than retried — deliberately. A missed run is
                    // recoverable from the admin's "Run now"; a double run means
                    // 50k duplicate emails.
                    Log::error('Promotion schedule claimed but could not be queued', [
                        'schedule_id' => $schedule->id,
                        'minute'      => $minute->toDateTimeString(),
                        'error'       => $e->getMessage(),
                    ]);
                }
            });

        $this->info("Dispatched {$dispatched} due schedule(s).");

        return self::SUCCESS;
    }

    /**
     * Take exclusive ownership of this schedule for the given minute.
     *
     * One conditional UPDATE does both the check and the write, so the guard is
     * atomic at the database level — unlike a read-then-save, which leaves a
     * window for a second process to pass the same check. Returns true only for
     * the caller that actually moved the row.
     */
    private function claim(EmailSchedule $schedule, CarbonInterface $minute): bool
    {
        return EmailSchedule::query()
            ->whereKey($schedule->getKey())
            ->where(function (Builder $query) use ($minute): void {
                $query->whereNull('last_run_at')->orWhere('last_run_at', '<', $minute);
            })
            ->update(['last_run_at' => $minute]) === 1;
    }
}
