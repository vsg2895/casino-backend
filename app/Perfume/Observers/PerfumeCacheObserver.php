<?php

declare(strict_types=1);

namespace App\Perfume\Observers;

use App\Perfume\Jobs\RevalidatePerfumeSite;
use App\Perfume\Support\PerfumeCache;
use Illuminate\Database\Eloquent\Model;

/**
 * Registered on every perfume_* model (PerfumeServiceProvider): any write
 * empties the store's cache and queues one storefront revalidation.
 *
 * Coarse on purpose. The catalog is small and every page shows pieces of
 * most tables (header nav, settings, brands…), so per-row invalidation
 * would buy little and cost a map of which page shows what.
 */
class PerfumeCacheObserver
{
    public function saved(Model $model): void
    {
        $this->bust();
    }

    public function deleted(Model $model): void
    {
        $this->bust();
    }

    private function bust(): void
    {
        PerfumeCache::flush();
        RevalidatePerfumeSite::dispatch();
    }
}
