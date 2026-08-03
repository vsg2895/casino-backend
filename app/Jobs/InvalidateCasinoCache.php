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

    /**
     * @param  int[]         $siteIds
     * @param  list<string>  $tags  Next.js cache tags to revalidate. The Laravel
     *                              side always flushes the whole site, but
     *                              Next.js only drops the tags it is told about
     *                              — anything omitted keeps serving its ISR copy
     *                              until the 1h window expires. Callers that
     *                              change more than casinos must say so.
     */
    public function __construct(
        private readonly array $siteIds,
        private readonly array $tags = ['casinos'],
    ) {}

    public function handle(): void
    {
        foreach ($this->siteIds as $siteId) {
            SiteCache::flushSite((int) $siteId);
        }

        if (! empty($this->siteIds)) {
            RevalidateNextJsSites::dispatch($this->tags, array_values($this->siteIds));
        }
    }
}
