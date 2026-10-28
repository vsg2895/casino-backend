<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The slow-request warning registered in AppServiceProvider.
 *
 * Durations are fed in through logQuery() rather than by running slow SQL:
 * the handler only ever sees the connection's running total, so this is the
 * same path a real slow query takes.
 */
class SlowQueryWarningTest extends TestCase
{
    public function test_work_under_the_threshold_logs_nothing(): void
    {
        Log::spy();

        DB::connection()->logQuery('select 1', [], 499.0);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_work_over_the_threshold_logs_one_warning(): void
    {
        Log::spy();

        DB::connection()->logQuery('select 1', [], 300.0);
        DB::connection()->logQuery('select 1', [], 300.0);
        DB::connection()->logQuery('select 1', [], 300.0);

        // Once per request, not once per query past the line.
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Slow database work in one request'
                && $context['total_ms'] === 600.0
                && $context['threshold_ms'] === 500
                && str_starts_with($context['route'], 'console: '),
        );
    }

    /** Verify and unsubscribe tokens live in the path; the URL must never be logged. */
    public function test_an_http_request_logs_the_route_pattern_not_the_url(): void
    {
        Route::get('/_slow-probe/{token}', static function (): string {
            DB::connection()->logQuery('select 1', [], 600.0);

            return 'ok';
        });
        Log::spy();

        $this->get('/_slow-probe/secret-token-value')->assertOk();

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $context['route'] === 'GET _slow-probe/{token}'
                && ! str_contains(json_encode($context), 'secret-token-value'),
        );
    }
}
