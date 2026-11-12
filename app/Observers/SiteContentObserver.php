<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\InvalidateCasinoCache;
use App\Models\CasinoSiteContent;
use App\Models\SpecialOfferSiteContent;

/**
 * A per-site override changes what ONE site serves, so only that site's
 * caches go: the Laravel SiteCache and the Next.js pages for casinos, offers
 * and the bonus area (RevalidationService adds the sitemap and the site tag).
 */
class SiteContentObserver
{
    public function saved(CasinoSiteContent|SpecialOfferSiteContent $row): void
    {
        $this->invalidate($row);
    }

    public function deleted(CasinoSiteContent|SpecialOfferSiteContent $row): void
    {
        $this->invalidate($row);
    }

    private function invalidate(CasinoSiteContent|SpecialOfferSiteContent $row): void
    {
        InvalidateCasinoCache::dispatch([(int) $row->site_id], ['casinos', 'special-offers', 'bonus']);
    }
}
