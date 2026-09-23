<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Article;
use App\Models\Site;
use App\Support\SiteCache;
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
     * Run every enabled source.
     *
     * @return array{created: int, skipped: int, failed: int, sources: list<array<string, mixed>>}
     */
    public function run(bool $dryRun = false, ?callable $report = null): array
    {
        $say = $report ?? static fn (string $line, string $level = 'line') => null;

        $site = Site::where('slug', config('news.site_slug'))->first();

        if ($site === null) {
            $say('No site with slug ' . config('news.site_slug') . ' — nothing written.', 'error');

            return ['created' => 0, 'skipped' => 0, 'failed' => 0, 'sources' => []];
        }

        $totals = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'sources' => []];
        $touched = [];
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
        }

        // Drafts are invisible to the public, so nothing needs expiring for a
        // dry run or for a run that wrote nothing. Done anyway when rows were
        // created, because the admin's listing reads through the same cache.
        if (! $dryRun && $totals['created'] > 0) {
            $this->refresh($site, $touched);
        }

        return $totals;
    }

    /**
     * One source: check permission, fetch, parse, write.
     *
     * @param  array<string, mixed>  $source
     * @return array{key: string, created: int, skipped: int, failed: int, slugs: list<string>}
     */
    private function runSource(Site $site, array $source, bool $dryRun, callable $say): array
    {
        $key  = (string) $source['key'];
        $out  = ['key' => $key, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'slugs' => []];
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

                $this->create($site, $item);
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
