<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ForumArticle;
use App\Support\Forum\ForumCounters;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a category's `articles_count` and last-post pointer current.
 *
 * Deleting an article is the interesting case and the one the acceptance
 * criteria name: its posts stay in the table (soft-deleted with it via the
 * service), so the category must lose both the article AND every post that
 * article contributed. Because the category's totals are recomputed from
 * `forum_articles` rather than accumulated, "lose exactly the right number" is
 * automatic — the sum simply no longer includes that row.
 */
class ForumArticleObserver
{
    public function created(ForumArticle $article): void
    {
        ForumCounters::refreshCategory((int) $article->forum_category_id);
    }

    public function updated(ForumArticle $article): void
    {
        // A move re-files the article AND everything under it, so both the old
        // and the new category change.
        if ($article->wasChanged('forum_category_id')) {
            DB::transaction(function () use ($article): void {
                ForumCounters::refreshCategory((int) $article->getOriginal('forum_category_id'));
                ForumCounters::refreshCategory((int) $article->forum_category_id);
            });
        }
    }

    public function deleted(ForumArticle $article): void
    {
        ForumCounters::refreshCategory((int) $article->forum_category_id);
    }

    public function restored(ForumArticle $article): void
    {
        ForumCounters::refreshCategory((int) $article->forum_category_id);
    }

    public function forceDeleted(ForumArticle $article): void
    {
        ForumCounters::refreshCategory((int) $article->forum_category_id);
    }
}
