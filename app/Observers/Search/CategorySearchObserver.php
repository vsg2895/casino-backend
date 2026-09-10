<?php

declare(strict_types=1);

namespace App\Observers\Search;

use App\Models\Category;
use App\Services\Search\SearchIndexer;

/**
 * Keeps `search_index` in step with Category.
 *
 * Every hook routes to ONE bounded upsert for this entity — never a section
 * rebuild. That matters most for reviews, which will outgrow every other table
 * indexed here.
 *
 * `saved` covers create and update together, and that is deliberate: it is not
 * enough to react to deletes, because unpublishing, deactivating or detaching is
 * what actually removes a row from the public site. The indexer re-derives
 * visibility from scratch on every call, so a flag flip in either direction
 * lands without this class knowing which flag changed.
 */
class CategorySearchObserver
{
    public function __construct(private readonly SearchIndexer $indexer) {}

    public function saved(Category $model): void
    {
        $this->indexer->syncCategory($model);
    }

    public function deleted(Category $model): void
    {
        // Soft OR hard delete. On a soft delete the sync recomputes visibility
        // (now empty) and clears the rows; a hard delete drops them outright.
        $this->indexer->syncCategory($model);
    }

    public function restored(Category $model): void
    {
        $this->indexer->syncCategory($model);
    }

    public function forceDeleted(Category $model): void
    {
        $this->indexer->remove($model);
    }
}
