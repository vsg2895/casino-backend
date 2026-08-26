<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// THE OVERLAP EXPIRY IS NOT COSMETIC. `withoutOverlapping()` with no argument
// holds its cache mutex for 24 HOURS. The mutex is released in a shutdown
// handler, so a run that is killed rather than finished — a deploy mid-tick, an
// OOM, a `kill -9`, a Redis restart that drops the release — leaves the lock
// behind and the command then silently no-ops EVERY MINUTE FOR A DAY. Nothing
// is logged, because the command never enters handle().
//
// Both sweeps finish in well under a second on any realistic list, so an
// explicit few minutes is far above any legitimate run and turns a stranded
// mutex from a day-long outage into a self-healing blip.
//
// If one is stuck right now: `php artisan schedule:clear-cache`.
//
// The 5 is written out at each call rather than hoisted into a constant: this
// file is re-evaluated on every application boot, and a file-scope `const` is
// then redefined on the second one.

// Check every minute for promotion campaigns that are due to run. Requires the
// system cron entry: `* * * * * php artisan schedule:run`.
Schedule::command('promotions:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping(5);

// Queue the global post-verification promotion for subscribers whose
// `newsletters.verified_at + delay_minutes` has elapsed. Every minute so the
// promotion lands close to the intended delay after the subscriber clicked
// their verify link, rather than drifting on a coarse tick.
Schedule::command('promotions:dispatch-verification')
    ->everyMinute()
    ->withoutOverlapping(5);

// Provision upcoming monthly partitions for the promotion history table.
Schedule::command('promotions:manage-history-partitions')
    ->monthlyOn(1, '04:30')
    ->withoutOverlapping();

//Schedule::command('test:command')->everyMinute();
