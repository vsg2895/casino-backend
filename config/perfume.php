<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Big Glow Day — the perfume storefront (sites/bigglowday)
|--------------------------------------------------------------------------
|
| A separate product from the casino network: its own tables (perfume_*),
| its own routes (/api/perfume/...), its own key. It is NOT a row in `sites`
| and never passes through VerifySiteAccess, SiteCache or the mailers.
|
| The storefront authenticates with ONE shared secret, `X-Perfume-Key`,
| compared in constant time by App\Perfume\Http\Middleware\VerifyPerfumeKey.
| There is a single storefront, so a table of hashed keys would be ceremony;
| rotate by changing the env value on both sides.
|
*/

return [

    // Shared with sites/bigglowday/.env.local PERFUME_API_KEY. Empty = every
    // public perfume request is refused (503), never silently open.
    'api_key' => (string) env('PERFUME_API_KEY', ''),

    // Where the storefront runs, for ISR revalidation after an admin edit:
    // POST {site_url}/api/revalidate with x-revalidate-secret.
    'site_url'          => rtrim((string) env('PERFUME_SITE_URL', 'http://localhost:3015'), '/'),
    'revalidate_secret' => (string) env('PERFUME_REVALIDATE_SECRET', ''),

    // Seconds a public read stays cached (same horizon as the casino sites).
    'cache_ttl' => 3600,

    // Catalog page size.
    'per_page' => 24,

];
