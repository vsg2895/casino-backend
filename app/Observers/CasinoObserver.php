<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Casino;
use App\Support\SiteCache;

class CasinoObserver
{
    public function saved(Casino $casino): void
    {
        $this->invalidate($casino);
    }

    public function deleted(Casino $casino): void
    {
        // Soft-deleting leaves the pivot intact — sites() still returns attached sites.
        $this->invalidate($casino);
    }

    private function invalidate(Casino $casino): void
    {
        $siteIds = $casino->sites()->pluck('sites.id')->all();

        if (empty($siteIds)) {
            return;
        }

        foreach ($siteIds as $siteId) {
            SiteCache::flushSite((int) $siteId);
        }

        RevalidateNextJsSites::dispatch(['casinos'], $siteIds);
    }
}
