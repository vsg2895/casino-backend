<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Article;
use App\Models\Site;
use App\Services\News\NewsIndexGate;
use App\Support\SiteCache;
use Illuminate\Console\Command;

/**
 * Takes a site's feed-made news out of search until an editor reviews it.
 *
 * Until 2026-11, `news:rewrite` cleared `noindex` as soon as its checks
 * passed, so posts built from a headline and a teaser were indexed with no
 * editor in the loop (SEO audit C1). This sets `noindex` on every such post of
 * one site that is still indexable. Nothing is deleted or hidden: the pages
 * stay published, listed and reachable; only search engines are told to skip
 * them, and they leave the sitemap. An editor unticks "Keep out of search results" per
 * post once it clears NewsIndexGate — or `news:rewrite --all` writes them again
 * and makes indexable each one whose new body clears it.
 *
 * Hand-written news is left alone. Idempotent: a second run finds nothing.
 *
 *   php artisan news:noindex-unreviewed winpalack --dry-run
 *   php artisan news:noindex-unreviewed winpalack
 */
class NoindexUnreviewedNews extends Command
{
    protected $signature = 'news:noindex-unreviewed
                            {site : Site slug or numeric id}
                            {--dry-run : List the posts and write nothing}';

    protected $description = "Set noindex on a site's indexable feed-made news posts until an editor reviews them";

    public function handle(): int
    {
        $identifier = (string) $this->argument('site');

        $site = ctype_digit($identifier)
            ? Site::query()->find((int) $identifier)
            : Site::query()->where('slug', $identifier)->first();

        if ($site === null) {
            $this->error('No site matches "' . $identifier . '".');

            return self::FAILURE;
        }

        $posts = Article::query()
            ->where('site_id', $site->id)
            ->ofType(Article::TYPE_NEWS)
            ->fromASource()
            ->where('noindex', false)
            ->orderByDesc('published_at')
            ->get(['id', 'slug', 'title', 'body', 'active']);

        if ($posts->isEmpty()) {
            $this->info("No indexable feed-made news on {$site->slug}. Nothing to do.");

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'slug', 'shown', 'words', 'internal link'],
            $posts->map(fn (Article $post): array => [
                $post->id,
                $post->slug,
                $post->active ? 'yes' : 'no',
                NewsIndexGate::wordCount((string) $post->body),
                NewsIndexGate::hasInternalLink((string) $post->body, $site) ? 'yes' : 'no',
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->warn("Dry run: {$posts->count()} post(s) on {$site->slug} would be set to noindex. Nothing written.");

            return self::SUCCESS;
        }

        // One statement, no model events: only the flag changes, and the
        // content date (content_updated_at) must not move for it.
        Article::query()->whereKey($posts->modelKeys())->update(['noindex' => true]);

        SiteCache::flushSite($site->id);
        RevalidateNextJsSites::dispatch(
            ['news', ...$posts->map(fn (Article $post): string => 'news:' . $post->slug)->all()],
            [$site->id],
        );

        $this->info("{$posts->count()} post(s) on {$site->slug} set to noindex. They stay published and reachable.");
        $this->line('Review each one in admin -> Sites -> News and untick "Keep out of search results" once it clears the bar.');

        return self::SUCCESS;
    }
}
