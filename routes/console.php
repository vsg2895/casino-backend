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

