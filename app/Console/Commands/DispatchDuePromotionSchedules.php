<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendScheduledPromotionJob;
use App\Models\EmailSchedule;
use Illuminate\Console\Command;

/**
 * Dispatches promotion campaigns for any schedule due at the current minute.
 *
 * Registered in routes/console.php to run every minute via the Laravel
 * scheduler (`php artisan schedule:run`, driven by a single system cron entry).
 * DB-driven schedules can't be declared statically, so this command is the
 * bridge: it reads active schedules and fans due ones out to the queue.
 */
class DispatchDuePromotionSchedules extends Command
{
    protected $signature = 'promotions:dispatch-due';

    protected $description = 'Queue promotion campaigns for schedules due this minute';

    public function handle(): int
    {
        $now = now();
        $dispatched = 0;

        EmailSchedule::query()
            ->where('active', true)
            ->get()
            ->each(function (EmailSchedule $schedule) use ($now, &$dispatched): void {
                if (! $schedule->isDue($now) || $schedule->ranAt($now)) {
                    return;
                }

                SendScheduledPromotionJob::dispatch($schedule->id);
                $schedule->forceFill(['last_run_at' => $now])->save();
                $dispatched++;
            });

        $this->info("Dispatched {$dispatched} due schedule(s).");

        return self::SUCCESS;
    }
}
