<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Forum\ForumViewCounter;
use Illuminate\Console\Command;

/**
 * Drains the Redis view buffer into `forum_articles.views_count`.
 *
 * Scheduled every minute. The window is deliberately short: a view count that
 * is a minute stale is indistinguishable from a live one to a reader, and a
 * longer window would mean losing more if Redis were lost.
 *
 *   php artisan forum:flush-views
 */
class FlushForumViews extends Command
{
    protected $signature = 'forum:flush-views';

    protected $description = 'Write buffered forum article view counts from Redis into MySQL';

    public function handle(): int
    {
        $updated = ForumViewCounter::flush();

        $this->info($updated === 0 ? 'No buffered views.' : "Updated {$updated} article(s).");

        return self::SUCCESS;
    }
}
