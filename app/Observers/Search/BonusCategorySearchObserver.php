<?php

declare(strict_types=1);

namespace App\Observers\Search;

use App\Models\BonusCategory;
use App\Services\Search\SearchIndexer;

/**
 * Keeps `search_index` in step with BonusCategory.
 *
 * Same shape as the other search observers: every hook routes to ONE bounded
 * upsert for this entity, never a section rebuild.
 *
 * `saved` covers create and update together on purpose. A bonus type is not
 * made searchable by being created — it is made searchable by a site publishing
 * a claimable offer under it — so the indexer re-derives visibility from
 * scratch on every call and this class never has to know which field changed.
 * Deactivating one clears its rows through exactly the same path.
 *
 * The offer side of that relationship is covered by SearchIndexer itself:
 * syncSpecialOffer() resyncs the offer's bonus category, because publishing or
 * hiding the last offer under a heading is what adds or removes the heading.
 */
class BonusCategorySearchObserver
{
    public function __construct(private readonly SearchIndexer $indexer) {}

    public function saved(BonusCategory $model): void
    {
        $this->indexer->syncBonusCategory($model);
    }

    public function deleted(BonusCategory $model): void
    {
        $this->indexer->syncBonusCategory($model);
    }

    public function restored(BonusCategory $model): void
    {
        $this->indexer->syncBonusCategory($model);
    }

    public function forceDeleted(BonusCategory $model): void
    {
        $this->indexer->remove($model);
    }
}
