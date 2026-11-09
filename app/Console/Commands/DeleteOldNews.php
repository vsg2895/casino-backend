<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Site;
use App\Services\News\NewsRemovalService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deletes a site's old, unused news posts without SEO damage.
 *
 * "Unused" is what nobody has invested in:
 *  - older than --older-than days (by publish date, or creation date for a
 *    post never published);
 *  - not on the home page strip (`featured`) or the Most Popular rail;
 *  - not reviewed: no "Reviewed by" and no Editor's note — unless
 *    --include-reviewed.
 * --drafts-only narrows it further to posts no visitor can see (hidden, no
 * body, or no publish date), which have no search footprint at all.
 *
 * Deletion goes through NewsRemovalService and is PERMANENT: the row and its
 * cover file are removed, a tombstone keeps the slug (410 Gone, never reused)
 * and the feed id (never re-scraped), links from other articles are unwrapped,
 * sitemaps and listings refreshed. Guides are never touched.
 *
 * --purge-trashed deletes for good what the old soft delete left behind.
 *
 *   php artisan news:delete-old winpalack --older-than=90 --dry-run
 *   php artisan news:delete-old winpalack --older-than=90 --force
 *   php artisan news:delete-old winpalack --drafts-only --older-than=7 --force
 *   php artisan news:delete-old winpalack --purge-trashed --force
 */
class DeleteOldNews extends Command
{
    /** Rows deleted per batch; each batch is one unlink pass. */
    private const int CHUNK = 200;

    /** Rows listed before "… and N more". */
    private const int PREVIEW_ROWS = 30;

    protected $signature = 'news:delete-old
                            {site : Site slug or numeric id}
                            {--older-than= : Only posts older than this many days}
                            {--purge-trashed : Permanently delete news soft-deleted before deletes were permanent}
                            {--drafts-only : Only posts that are not visible to the public}
                            {--include-reviewed : Also delete posts with a reviewer or an editor\'s note}
                            {--dry-run : List the posts and delete nothing}
                            {--force : Delete without asking (needed when not interactive)}';

    protected $description = "Delete a site's old unused news posts (410 Gone, links unwrapped, sitemaps refreshed)";

    public function handle(NewsRemovalService $removal): int
    {
        $identifier = (string) $this->argument('site');

        $site = ctype_digit($identifier)
            ? Site::query()->find((int) $identifier)
            : Site::query()->where('slug', $identifier)->first();

        if ($site === null) {
            $this->error('No site matches "' . $identifier . '".');

            return self::FAILURE;
        }

        $days = (string) $this->option('older-than');

        if ($this->option('purge-trashed')) {
            $purged = $this->purgeTrashed($site, $removal);

            if ($purged === null) {
                return self::SUCCESS;
            }

            if ($days === '') {
                return self::SUCCESS;
            }
        }

        if (! ctype_digit($days) || (int) $days < 1) {
            $this->error('--older-than=<days> is required, a whole number of at least 1.');

            return self::FAILURE;
        }

        $ids = $this->candidates($site, (int) $days)->orderBy('id')->pluck('id')->all();

        if ($ids === []) {
            $this->info("No news on {$site->slug} matches. Nothing to delete.");

            return self::SUCCESS;
        }

        $this->preview($ids);

        $count = count($ids);

        if ($this->option('dry-run')) {
            $this->warn("Dry run: {$count} news post(s) on {$site->slug} would be deleted. Nothing written.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$count} news post(s) on {$site->slug}?")) {
            $this->line('Nothing deleted.');

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            // Refreshed once per batch: a run of thousands still revalidates
            // the site a handful of times, never per post.
            $deleted += count($removal->remove($site, Article::query()->whereKey($chunk)->get()));
        }

        $this->info("{$deleted} news post(s) on {$site->slug} deleted.");
        $this->line('Their URLs now answer 410 Gone, links to them from other articles were unwrapped,');
        $this->line('and sitemap.xml / news-sitemap.xml no longer list them.');

        return self::SUCCESS;
    }

    /**
     * Rows the old soft delete left behind, deleted for good now. Returns how
     * many, or null when the operator declined.
     */
    private function purgeTrashed(Site $site, NewsRemovalService $removal): ?int
    {
        $count = Article::onlyTrashed()->where('site_id', $site->id)->ofType(Article::TYPE_NEWS)->count();

        if ($count === 0) {
            $this->info("No soft-deleted news on {$site->slug} to purge.");

            return 0;
        }

        if ($this->option('dry-run')) {
            $this->warn("Dry run: {$count} soft-deleted news post(s) on {$site->slug} would be deleted for good.");

            return 0;
        }

        if (! $this->option('force') && ! $this->confirm("Permanently delete {$count} soft-deleted news post(s) on {$site->slug}?")) {
            $this->line('Nothing deleted.');

            return null;
        }

        $purged = count($removal->purgeTrashed($site));
        $this->info("{$purged} soft-deleted news post(s) on {$site->slug} deleted for good. Their URLs keep answering 410.");

        return $purged;
    }

    /** @return Builder<Article> */
    private function candidates(Site $site, int $days): Builder
    {
        $cutoff = now()->subDays($days);

        return Article::query()
            ->where('site_id', $site->id)
            ->ofType(Article::TYPE_NEWS)
            ->where('featured', false)
            ->where('to_be_most_popular', false)
            ->where(static function (Builder $q) use ($cutoff): void {
                $q->where('published_at', '<', $cutoff)
                    ->orWhere(static fn (Builder $q) => $q->whereNull('published_at')->where('created_at', '<', $cutoff));
            })
            ->when(! $this->option('include-reviewed'), static function (Builder $q): void {
                $q->whereNull('reviewed_by_id')
                    ->where(static fn (Builder $q) => $q->whereNull('editor_note')->orWhere('editor_note', ''));
            })
            ->when($this->option('drafts-only'), static function (Builder $q): void {
                $q->where(static function (Builder $q): void {
                    $q->where('active', false)
                        ->orWhereNull('body')
                        ->orWhere('body', '')
                        ->orWhereNull('published_at')
                        ->orWhere('published_at', '>', now());
                });
            });
    }

    /** @param  list<int>  $ids */
    private function preview(array $ids): void
    {
        $rows = Article::query()
            ->whereKey(array_slice($ids, 0, self::PREVIEW_ROWS))
            ->orderBy('published_at')
            ->get(['id', 'slug', 'published_at', 'active', 'noindex']);

        $this->table(
            ['id', 'slug', 'published', 'shown', 'indexable'],
            $rows->map(static fn (Article $post): array => [
                $post->id,
                $post->slug,
                $post->published_at?->toDateString() ?? '—',
                $post->active ? 'yes' : 'no',
                $post->noindex ? 'no' : 'yes',
            ])->all(),
        );

        if (count($ids) > self::PREVIEW_ROWS) {
            $this->line('… and ' . (count($ids) - self::PREVIEW_ROWS) . ' more.');
        }
    }
}
