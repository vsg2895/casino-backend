<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Trust only the proxies this platform actually runs behind — the
         * local nginx and, when enabled, Cloudflare's published ranges. See
         * config/proxies.php for why `at: '*'` was wrong: it let any caller
         * forge their own address with a header, which both corrupts visitor
         * records and defeats every `throttle:` limit in routes/api.php.
         */
        $middleware->trustProxies(at: \App\Support\TrustedProxies::list());
        $middleware->alias([
            'verify.site' => \App\Http\Middleware\VerifySiteAccess::class,
            'auth'        => \App\Http\Middleware\Authenticate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
