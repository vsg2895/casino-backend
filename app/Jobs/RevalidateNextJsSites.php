<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\RevalidationService;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Pings the Next.js revalidation webhook for each affected site.
 *
 * Intentionally NOT queued so on-demand revalidation works without a running
 * queue worker. RevalidationService swallows HTTP errors and uses a short
 * timeout, so a slow/down site can never break an admin write.
 */
class RevalidateNextJsSites
{
    use Dispatchable;

    /**
     * @param string[] $tags    Cache tags to revalidate (e.g. ['casinos'])
     * @param int[]    $siteIds Site IDs whose Next.js apps should revalidate
     */
    public function __construct(
        public readonly array $tags,
        public readonly array $siteIds,
    ) {}

    public function handle(RevalidationService $revalidation): void
    {
        $revalidation->revalidate($this->tags, $this->siteIds);
    }
}
