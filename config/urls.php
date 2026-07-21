<?php

declare(strict_types=1);

/**
 * Environment-aware URLs for the platform's shared backend + admin.
 *
 * NOTE: public SITE urls live under 'sites' and are ALWAYS the real public
 * https domain — they are baked into emails (verify / unsubscribe links) that
 * are delivered to real inboxes, so they must never point at localhost even in
 * local development. Each entry is env-overridable (e.g. to open the flow against
 * a local dev server) but defaults to the live domain. Sites not listed here fall
 * back to `https://{site->domain}` automatically (see Site::frontendBaseUrl()).
 */
$debug = (bool) env('APP_DEBUG', false);

return [
    'debug' => $debug,

    // Shared backend API + admin SPA (dev vs prod).
    'api'   => $debug ? 'http://localhost:8000' : 'https://api.idevaffiliation.com',
    'admin' => $debug ? 'http://localhost:5173' : 'https://admin.idevaffiliation.com',

    // Public sites, keyed by slug. Real https domains, used in email links.
    // Override per site with SITE_URL_<SLUG> only if you must (e.g. local testing).
    'sites' => [
        'idevaffiliation' => env('SITE_URL_IDEVAFFILIATION', 'https://idevaffiliation.com'),
        'winpalack'       => env('SITE_URL_WINPALACK', 'https://winpalack.com'),
    ],
];
