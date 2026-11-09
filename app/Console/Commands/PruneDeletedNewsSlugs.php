<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DeletedNewsSlug;
use Illuminate\Console\Command;

/**
 * Drops tombstones of hard-deleted news once they have done their job, so
 * `deleted_news_slugs` holds at most NEWS_GONE_DAYS of deletions and never
 * grows without bound.
 *
 * Safe past that window, for each thing a tombstone is for:
 *  - 410 Gone: NewsController::gone() already ignores rows older than the
 *    window; a year after removal the URL is long out of the index;
 *  - the feed id: news:scrape only takes items from the last day
 *    (NEWS_MAX_AGE_HOURS), so a year-old item never comes back anyway;
 *  - the slug: a URL search engines dropped long ago may be reused.
 *
 * Scheduled daily (routes/console.php).
 *
 *   php artisan news:prune-deleted-slugs --dry-run
 */
class PruneDeletedNewsSlugs extends Command
{
    protected $signature = 'news:prune-deleted-slugs {--dry-run : Count and delete nothing}';

    protected $description = 'Delete tombstones of hard-deleted news older than NEWS_GONE_DAYS';

    public function handle(): int
    {
        if (! DeletedNewsSlug::ready()) {
            $this->line('deleted_news_slugs does not exist yet. Nothing to do.');

            return self::SUCCESS;
        }

        $old = DeletedNewsSlug::query()->expired();

        if ($this->option('dry-run')) {
            $this->info($old->count() . ' tombstone(s) would be deleted.');

            return self::SUCCESS;
        }

        $this->info($old->delete() . ' tombstone(s) deleted.');

        return self::SUCCESS;
    }
}
