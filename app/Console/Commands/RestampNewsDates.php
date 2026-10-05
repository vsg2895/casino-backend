<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Article;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Moves every live news post's publish date to a recent day, in one pass.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * Ingested posts carry the SOURCE's publication date. A batch collected on one
 * day and approved over the following weeks therefore reaches the public with
 * every card reading the same "12 days ago" — the date of someone else's
 * publication, not of ours. {@see Article::stampPublishDateOnGoingLive()} fixes
 * this going forward, at the moment a post is switched on; this command is the
 * one-off repair for everything that went live before that rule existed.
 *
 * ── Why the rows do not all get the SAME timestamp ──────────────────────────
 *
 * The feed and the Most Popular rail both order by `published_at`. Writing one
 * identical value to every row would collapse that ordering onto the id
 * tiebreak and shuffle the feed into import order. So the rows are read newest
 * first and stamped one second apart, which preserves exactly the order they
 * are in today while putting all of them inside the same day — "yesterday" on
 * every card, with the newest still first.
 *
 * Re-running it is safe: it reads the current order and rewrites it to the same
 * shape, one day back from whenever it is run.
 *
 *   php artisan news:restamp --dry-run          # see what would change
 *   php artisan news:restamp                    # every site, live posts, 1 day ago
 *   php artisan news:restamp --site=winpalack --days=2
 *   php artisan news:restamp --drafts           # unapproved rows too
 */
class RestampNewsDates extends Command
{
    protected $signature = 'news:restamp
        {--site= : Limit to one site, by slug or id}
        {--days=1 : How many days back the newest post should land}
        {--drafts : Include posts that are not active yet}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Restamp news publish dates to a recent day, keeping their current order';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = max(0, (int) $this->option('days'));
        $sites = $this->sites();

        if ($sites->isEmpty()) {
            $this->error('No matching site.');

            return self::FAILURE;
        }

        // ONE base instant for the whole run, so two sites processed a second
        // apart do not end up a second apart in the data.
        $base = Carbon::now()->subDays($days);
        $rows = [];
        $touched = 0;

        foreach ($sites as $site) {
            $articles = Article::query()
                ->where('site_id', $site->id)
                ->ofType(Article::TYPE_NEWS)
                ->when(! $this->option('drafts'), fn ($q) => $q->where('active', true))
                // The order the public sees today — the thing this command must
                // not disturb. `id` breaks ties, exactly as the rail does.
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->get(['id', 'slug', 'published_at']);

            if ($articles->isEmpty()) {
                continue;
            }

            foreach ($articles->values() as $i => $article) {
                $stamp = $base->copy()->subSeconds($i);

                if (! $dryRun) {
                    // A query-builder update, NOT $article->save(): saving a
                    // model loaded without its `body` would recompute
                    // `read_minutes` from an empty string and wipe it.
                    Article::query()->whereKey($article->id)->update(['published_at' => $stamp]);
                }

                $touched++;
            }

            $rows[] = [
                $site->slug,
                $articles->count(),
                (string) $articles->first()->published_at,
                (string) $base,
            ];

            if (! $dryRun) {
                SiteCache::flushSite($site->id);
                $this->revalidate($site, $articles->pluck('slug')->all());
            }
        }

        if ($rows === []) {
            $this->info('No news posts to restamp.');

            return self::SUCCESS;
        }

        $this->table(['Site', 'Posts', 'Newest was', 'Newest now'], $rows);
        $this->info(($dryRun ? 'Would restamp ' : 'Restamped ') . $touched . ' news post(s).');

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Site> */
    private function sites()
    {
        $filter = trim((string) $this->option('site'));

        return Site::query()
            ->when($filter !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('slug', $filter)->orWhere('id', (int) $filter),
            ))
            ->orderBy('id')
            ->get(['id', 'slug']);
    }

    /**
     * Expire the listing and every detail page that changed.
     *
     * Both are needed: the cards carry the date, and so does the article page.
     * Tags go out in batches so a site with hundreds of posts does not become
     * one enormous webhook payload.
     *
     * @param  list<string>  $slugs
     */
    private function revalidate(Site $site, array $slugs): void
    {
        foreach (array_chunk($slugs, 50) as $i => $chunk) {
            $tags = array_map(fn (string $slug) => 'news:' . $slug, $chunk);

            RevalidateNextJsSites::dispatch($i === 0 ? ['news', ...$tags] : $tags, [$site->id]);
        }
    }
}
