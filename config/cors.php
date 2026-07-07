<?php

declare(strict_types=1);

/**
 * CORS — only the admin SPA calls the API from a browser (public sites fetch
 * server-side). Allowed origins are env-aware: localhost in debug, the live
 * admin domain in production.
 */
$debug = (bool) env('APP_DEBUG', false);

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $debug
        ? ['http://localhost:5173', 'http://localhost:3000', 'http://localhost:3002']
        : ['https://admin.idevaffiliation.com'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Admin auth is a Bearer token, not cookies — credentials not needed.
    'supports_credentials' => false,
];
