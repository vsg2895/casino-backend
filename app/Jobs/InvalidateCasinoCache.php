<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\SiteCache;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Flushes the per-site public cache for the given sites and pings Next.js to
 * revalidate. Intentionally NOT queued: cache invalidation must take effect
 * immediately after an admin attach/detach/sync, even when no queue worker is
 * running. (The Next.js revalidation webhook it dispatches stays queued.)
 */
class InvalidateCasinoCache
{
    use Dispatchable;

    /** @param int[] $siteIds */
    public function __construct(private readonly array $siteIds) {}

    public function handle(): void
    {
        foreach ($this->siteIds as $siteId) {
            SiteCache::flushSite((int) $siteId);
        }

        if (! empty($this->siteIds)) {
            RevalidateNextJsSites::dispatch(['casinos'], array_values($this->siteIds));
        }
    }
}
