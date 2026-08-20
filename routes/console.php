<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Check every minute for promotion campaigns that are due to run. Requires the
// system cron entry: `* * * * * php artisan schedule:run`.
Schedule::command('promotions:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping();

// Queue the global post-verification promotion for subscribers whose
// `newsletters.verified_at + delay_minutes` has elapsed. Every minute so the
// promotion lands close to the intended delay after the subscriber clicked
// their verify link, rather than drifting on a coarse tick.
Schedule::command('promotions:dispatch-verification')
    ->everyMinute()
    ->withoutOverlapping();

// Provision upcoming monthly partitions for the promotion history table.
Schedule::command('promotions:manage-history-partitions')
    ->monthlyOn(1, '04:30')
    ->withoutOverlapping();

//Schedule::command('test:command')->everyMinute();
