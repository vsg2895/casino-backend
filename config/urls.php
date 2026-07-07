<?php

declare(strict_types=1);

/**
 * Environment-aware URLs for the whole platform.
 *
 * When APP_DEBUG is true (local) everything points at localhost; when false
 * (production) it points at the live domains. One backend + admin serve every
 * site, so `api` and `admin` are shared; each public site has its own URL.
 */
$debug = (bool) env('APP_DEBUG', false);

return [
    'debug' => $debug,

    // Shared backend API + admin SPA.
    'api'   => $debug ? 'http://localhost:8000' : 'https://api.idevaffiliation.com',
    'admin' => $debug ? 'http://localhost:5173' : 'https://admin.idevaffiliation.com',

    // Public sites, keyed by slug (dev ports match each site's PORT).
    'sites' => [
        'idevaffiliation' => $debug ? 'http://localhost:3000' : 'https://idevaffiliation.com',
        'winpalack'       => $debug ? 'http://localhost:3001' : 'https://winpalack.com',
    ],
];
