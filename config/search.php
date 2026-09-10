<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Site search
|--------------------------------------------------------------------------
|
| Drives the header overlay's grouped suggestions. Everything is served from
| the denormalised `search_index` table rather than by UNION-ing the entities,
| because the five sections scope to a site three different ways — casinos
| through the `casino_site` pivot, special offers indirectly through their
| casino's pivot, reviews and pages through their own `site_id`, and categories
| not at all (they are global rows whose visibility is DERIVED from whether any
| attached casino is active on the site). One flat, pre-scoped table turns that
| into a single indexed query per keystroke.
|
*/

return [

    /*
    | Sections, in display order.
    |
    | `weight` is a static per-section boost multiplied into the MATCH()
    | relevance score. The spread matters more than the absolute numbers: a
    | casino must outrank a review that merely mentions it, which is why `forum`
    | is deliberately the lowest of the five. Casinos match mainly on title,
    | reviews mainly on body, and body matches already score lower — the weight
    | makes that ordering explicit rather than incidental.
    |
    | `label` is what the pill and the "in <Section>" suffix render.
    */
    'sections' => [
        'casinos' => [
            'label'  => 'Casinos',
            'weight' => 100,
        ],
        'special_offers' => [
            'label'  => 'Special Offers',
            'weight' => 80,
        ],
        'categories' => [
            'label'  => 'Categories',
            'weight' => 70,
        ],
        'pages' => [
            'label'  => 'Pages',
            'weight' => 50,
        ],
        'forum' => [
            'label'  => 'Forum',
            'weight' => 30,
        ],
    ],

    // Results per section in "All" mode, and per page for a single section.
    'per_page' => 5,

    /*
    | InnoDB will not index a token shorter than `innodb_ft_min_token_size`
    | (default 3), so a 1–2 character query returns NOTHING from FULLTEXT. Below
    | this length the service switches to an indexed `LIKE 'x%'` prefix scan
    | against `title`.
    |
    | Read from config rather than hardcoded because the server variable can
    | differ; it must match the DB or short queries silently return nothing.
    | Verify with: SHOW VARIABLES LIKE 'innodb_ft_min_token_size';
    */
    'min_token_size' => (int) env('SEARCH_MIN_TOKEN_SIZE', 3),

    // Characters that mean something to InnoDB's boolean-mode parser. Stripped
    // from user input before the query is built, so typing an operator can
    // neither break the query nor abuse it. See SearchService::sanitize().
    'boolean_operators' => '+-><()~*"@',

    // Longest review/description excerpt copied into `search_index.body`.
    // Unbounded user text must never be duplicated into the index wholesale.
    'body_limit' => 2000,

    // Seconds a suggest response is cached per (site, query, section, page).
    // Short: the endpoint is hit on every keystroke, so the win is in collapsing
    // a burst of identical requests, not in long-lived caching.
    'cache_ttl' => 60,
];
