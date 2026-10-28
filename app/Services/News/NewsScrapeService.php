<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Article;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches the configured feeds and files what is new as DRAFTS.
 *
 * ── What it writes, and what it refuses to ──────────────────────────────────
 *
 * Rows go into `articles` with `type = news`, through the same fields the admin
 * screen fills, so a scraped post is indistinguishable from a hand-written one
 * once an editor has approved it. Nothing is ever published: every row lands
 * `active = false`, and the existing approval flow is the only way out of that.
 *
 * The source's prose never reaches the database. `title` and `excerpt` hold the
 * source's headline and teaser only as the INPUT the rewriter needs, in a row
 * that cannot be seen by anybody but an editor, and {@see NewsRewriteService}
 * replaces both with original copy. The full body is never read at all.
 *
 * ── Dedup ───────────────────────────────────────────────────────────────────
 *
 * On `source_ref` — "<source key>:<post id>" — behind a
 * `unique(site_id, source_ref)` index. The id is the publisher's own, stable
 * for the life of the post and identical on every re-fetch, so the same item is
 * recognised however its headline is later edited.
 *
 * This is also what frees the SLUG. An earlier version had no column to key on
 * and deduped on a slug derived from the source's headline, which put the
 * source's wording in our URL and made the slug load-bearing. Now the slug is
 * only a URL, and {@see NewsRewriteService} replaces it with one built from the
 * original title it writes.
 *
 * A collision with a hand-written article of the same slug still causes a SKIP
 * rather than an overwrite — the safe direction — and is reported.
 */
class NewsScrapeService
{
    public function __construct(
        private readonly NewsFeedParser $parser,
        private readonly NewsCoverGenerator $covers,
    ) {}

    /**
     * Run every enabled source, then drop the oldest day — see pruneOldestDay().
     *
     * @return array{created: int, skipped: int, failed: int, pruned: int, sources: list<array<string, mixed>>}
     */
    public function run(bool $dryRun = false, ?callable $report = null, bool $prune = true): array
    {
        $say = $report ?? static fn (string $line, string $level = 'line') => null;

        $site = Site::where('slug', config('news.site_slug'))->first();

        if ($site === null) {
            $say('No site with slug ' . config('news.site_slug') . ' — nothing written.', 'error');

            return ['created' => 0, 'skipped' => 0, 'failed' => 0, 'pruned' => 0, 'sources' => []];
        }

        $totals = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'pruned' => 0, 'sources' => []];
        $touched = [];
        $createdIds = [];
        $sources = array_values(array_filter(config('news.sources', []), static fn (array $s) => $s['enabled'] ?? false));

        if ($sources === []) {
            $say('No source is enabled in config/news.php.', 'warn');

            return $totals;
        }

        foreach ($sources as $i => $source) {
            if ($i > 0) {
                // Politeness between hosts, not a limit anyone imposed on us.
                sleep((int) config('news.delay_seconds', 2));
            }

            $result = $this->runSource($site, $source, $dryRun, $say);

            $totals['created'] += $result['created'];
            $totals['skipped'] += $result['skipped'];
            $totals['failed']  += $result['failed'];
            $totals['sources'][] = $result;
            $touched = [...$touched, ...$result['slugs']];
            $createdIds = [...$createdIds, ...$result['ids']];
        }

        // Only when today's news came in: a run that found nothing new must
        // not shrink the feed with nothing to replace what it removes.
        if ($prune && config('news.prune_oldest_day', true) && $totals['created'] > 0) {
            $pruned = $this->pruneOldestDay($site, $createdIds, $dryRun, $say);
            $totals['pruned'] = count($pruned);
            $touched = [...$touched, ...$pruned];
        }

        // Drafts are invisible to the public, so nothing needs expiring for a
        // dry run or for a run that wrote nothing. Done anyway when rows were
        // created, because the admin's listing reads through the same cache —
        // and when live posts were pruned, their pages must stop serving.
        if (! $dryRun && ($totals['created'] > 0 || $totals['pruned'] > 0)) {
            $this->refresh($site, $touched);
        }

        return $totals;
    }

    /**
     * Delete every news post of the oldest day on the site: the rolling window
     * that keeps the feed to recent news. Returns the slugs it deleted (or, on
     * a dry run, would delete).
     *
     * Each row is deleted as a MODEL, never with a bulk query, so
     * Article::deleted fires and the cover file goes with it.
     *
     * ONCE PER DAY. A scraped row created earlier today means an earlier run
     * already took today's news, and so already removed a day — without this,
     * the hourly schedule would delete 24 days a day.
     *
     * Never touched: the rows this run created, posts from the last day, and
     * posts with no date (a hand-written draft has nothing to age by).
     *
     * @param list<int> $createdIds
     * @return list<string>
     */
    private function pruneOldestDay(Site $site, array $createdIds, bool $dryRun, callable $say): array
    {
        $news = fn () => Article::query()
            ->where('site_id', $site->id)
            ->ofType(Article::TYPE_NEWS)
            ->whereNotIn('id', $createdIds);

        $alreadyRanToday = Article::withTrashed()
            ->where('site_id', $site->id)
            ->ofType(Article::TYPE_NEWS)
            ->whereNotNull('source_ref')
            ->whereNotIn('id', $createdIds)
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();

        if ($alreadyRanToday) {
            $say('Oldest day already removed today — nothing deleted.', 'line');

            return [];
        }

        $oldest = $news()->whereNotNull('published_at')->orderBy('published_at')->value('published_at');

        if ($oldest === null) {
            return [];
        }

        $day = Carbon::parse($oldest)->startOfDay();

        if ($day->greaterThanOrEqualTo(now()->subDay()->startOfDay())) {
            $say('Every post is from the last day — nothing deleted.', 'line');

            return [];
        }

        $doomed = $news()
            ->whereBetween('published_at', [$day, $day->copy()->endOfDay()])
            ->get();

        $slugs = [];

        foreach ($doomed as $article) {
            if (! $dryRun) {
                $article->delete();
            }

            $slugs[] = (string) $article->slug;
        }

        $say(sprintf(
            '%s %d news post(s) from %s, the oldest day.',
            $dryRun ? 'Would delete' : 'Deleted',
            count($slugs),
            $day->toDateString(),
        ), 'warn');

        return $slugs;
    }

    /**
     * One source: check permission, fetch, parse, write.
     *
     * @param  array<string, mixed>  $source
     * @return array{key: string, created: int, skipped: int, failed: int, slugs: list<string>, ids: list<int>}
     */
    private function runSource(Site $site, array $source, bool $dryRun, callable $say): array
    {
        $key  = (string) $source['key'];
        $out  = ['key' => $key, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'slugs' => [], 'ids' => []];
        $url  = (string) $source['url'];

        $say("· {$key}", 'line');

        $permitted = $this->robotsPermit($url);

        if ($permitted === false) {
            // An explicit refusal in robots.txt is a decision by the publisher,
            // and it ends this source's run rather than being noted and ignored.
            $say("  robots.txt disallows {$url} — skipped.", 'error');
            $out['failed']++;

            return $out;
        }

        try {
            $response = Http::withUserAgent((string) config('news.user_agent'))
                ->timeout((int) config('news.timeout', 20))
                ->get($url);
        } catch (Throwable $e) {
            $say('  request failed: ' . $e->getMessage(), 'error');
            $out['failed']++;

            return $out;
        }

        if (! $response->successful()) {
            $say("  HTTP {$response->status()} — skipped.", 'error');
            $out['failed']++;

            return $out;
        }

        $parsed = $this->parser->parse(
            $response->body(),
            $key,
            (string) $source['name'],
            (int) config('news.min_teaser_chars', 40),
        );

        foreach ($parsed['skipped'] as $reason) {
            // Named, never silent: a selector or a feed that quietly stops
            // yielding items is the failure mode nobody notices.
            $say("  skipped — {$reason}", 'warn');
            $out['skipped']++;
        }

        // The last day's news only. Counted rather than listed: a feed carries
        // dozens of older items on every run, and naming each one is noise.
        $maxAgeHours = (int) config('news.max_age_hours', 24);

        if ($maxAgeHours > 0) {
            $cutoff = now()->subHours($maxAgeHours);
            $fresh = array_values(array_filter(
                $parsed['items'],
                static fn (array $item): bool => $item['published_at']->greaterThanOrEqualTo($cutoff),
            ));
            $old = count($parsed['items']) - count($fresh);

            if ($old > 0) {
                $say("  {$old} older than {$maxAgeHours}h — skipped", 'line');
                $out['skipped'] += $old;
            }

            $parsed['items'] = $fresh;
        }

        foreach ($parsed['items'] as $item) {
            try {
                if ($this->exists($site, $item['source_ref'], $item['slug'])) {
                    $out['skipped']++;

                    continue;
                }

                if ($dryRun) {
                    $say("  + would create: {$item['slug']}", 'line');
                    $out['created']++;

                    continue;
                }

                $out['ids'][] = (int) $this->create($site, $item)->id;
                $out['created']++;
                $out['slugs'][] = $item['slug'];
                $say("  + {$item['slug']}", 'info');
            } catch (Throwable $e) {
                // One bad row must not end the run.
                $out['failed']++;
                $say('  failed: ' . $e->getMessage(), 'error');
                Log::warning('news:scrape could not store an item', [
                    'source' => $key,
                    'slug'   => $item['slug'] ?? null,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        $say(sprintf('  %d new, %d skipped, %d failed', $out['created'], $out['skipped'], $out['failed']), 'line');

        return $out;
    }

    /**
     * Have we already taken this item, or is its slug spoken for?
     *
     * TWO questions, because they fail differently. `source_ref` answers "we
     * have this already" and is the real dedup. The slug check catches a
     * hand-written article that happens to occupy the URL — inserting would hit
     * the `unique(site_id, slug)` index and throw, so it is skipped and
     * reported instead.
     *
     * withTrashed: a soft-deleted row still holds both unique indexes.
     */
    private function exists(Site $site, string $sourceRef, string $slug): bool
    {
        return Article::withTrashed()
            ->where('site_id', $site->id)
            ->where(fn ($q) => $q->where('source_ref', $sourceRef)->orWhere('slug', $slug))
            ->exists();
    }

    /** @param array<string, mixed> $item */
    private function create(Site $site, array $item): Article
    {
        return Article::create([
            'site_id' => $site->id,
            'type'    => Article::TYPE_NEWS,
            'slug'    => $item['slug'],

            // Where it came from. `source_ref` is the dedup key; the other two
            // are what the admin lists and what the public page credits.
            'source_ref'  => $item['source_ref'],
            'source_name' => $item['source_name'],
            'source_url'  => $item['url'],

            // The source's words, held as the rewriter's input in a row no
            // visitor can reach. NewsRewriteService replaces both.
            'title'   => $item['headline'],
            'excerpt' => $item['teaser'],

            // NULL is the "not yet rewritten" flag. There is no status column,
            // and none is needed: an article with no body has not been written.
            'body'    => null,

            // The source's real date, so the feed's chronology is truthful the
            // moment an editor approves it. Safe because `active` is false, and
            // scopeVisible() requires BOTH.
            'published_at' => $item['published_at'],

            // The approval gate. Nothing scraped is ever publicly visible until
            // a person turns this on.
            'active'   => false,
            'featured' => false,

            // Feed categories are the source's taxonomy ("NFL", "Latest News",
            // "UKGC") and do not map onto this site's. Null is a valid,
            // publishable state, so the editor files it when they approve.
            'news_category_id' => null,

            /*
             * NOINDEX until the rewrite has run.
             *
             * Before that, `title` and `excerpt` still hold the SOURCE's
             * headline and teaser — they are the rewriter's input. The approval
             * gate is what normally stops those reaching the public, but an
             * editor who publishes a draft early would put another
             * publisher's wording on this domain and invite a search engine to
             * index it as ours. This makes that mistake harmless rather than
             * relying on nobody making it. NewsRewriteService clears it once
             * the copy is genuinely original.
             */
            'noindex' => true,

            // DRAWN, never taken. The feeds do carry photographs and they are
            // deliberately not used — see NewsCoverGenerator for why. Null when
            // generation fails, which every listing already renders as a
            // text-only card.
            'hero_image_path' => $this->covers->generate($item['slug'], $item['headline'], $item['teaser']),
        ]);
    }

    /**
     * Whether robots.txt permits the feed.
     *
     * Null means "could not tell" — no robots.txt, or an unreachable one — and
     * is treated as permission, which is what the standard says and what every
     * crawler does. Only an explicit Disallow that matches stops the run.
     */
    private function robotsPermit(string $url): ?bool
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        try {
            $response = Http::withUserAgent((string) config('news.user_agent'))
                ->timeout((int) config('news.timeout', 20))
                ->get($parts['scheme'] . '://' . $parts['host'] . '/robots.txt');
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return $this->allowedByRobots($response->body(), $path);
    }

    /**
     * Read the `*` group only.
     *
     * We are not any named agent, so a directive addressed to Googlebot or to
     * AhrefsBot says nothing about us. An empty `Disallow:` is the standard's
     * way of allowing everything and must not be read as a prefix match on "".
     */
    private function allowedByRobots(string $robots, string $path): bool
    {
        $inStar = false;

        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');

            if ($line === '') {
                continue;
            }

            if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m) === 1) {
                $inStar = trim($m[1]) === '*';

                continue;
            }

            if ($inStar && preg_match('/^disallow:\s*(.*)$/i', $line, $m) === 1) {
                $rule = trim($m[1]);

                if ($rule !== '' && str_starts_with($path, $rule)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The same invalidation the admin's own save performs.
     *
     * Drafts are invisible, so this changes nothing a visitor sees today — it
     * exists so the section is already consistent when an editor approves one.
     *
     * @param list<string> $slugs
     */
    private function refresh(Site $site, array $slugs): void
    {
        SiteCache::flushSite($site->id);

        $tags = ['news'];

        foreach (array_unique($slugs) as $slug) {
            $tags[] = 'news:' . $slug;
        }

        RevalidateNextJsSites::dispatch($tags, [$site->id]);
    }
}
