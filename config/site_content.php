<?php

declare(strict_types=1);

/**
 * Per-site content overrides for casinos and special offers.
 */
return [
    /*
     * The site whose content IS the records' own columns — the default every
     * other site falls back to. It never gets override rows: the admin edits
     * its text through the record's global fields, and the override endpoints
     * refuse it. A slug, so it survives a reseed.
     */
    'default_site_slug' => env('DEFAULT_CONTENT_SITE_SLUG', 'winpalack'),
];
