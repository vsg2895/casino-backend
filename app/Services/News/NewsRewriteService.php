<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Article;
use App\Models\DeletedNewsSlug;
use App\Models\Author;
use App\Models\Casino;
use App\Models\Category;
use App\Models\Site;
use App\Support\SchemaGuard;
use App\Support\SiteCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Rewrites scraped facts into this site's own editorial.
 *
 * ── What "rewrite" means here, precisely ────────────────────────────────────
 *
 * The model is given TWO things: the source's headline and its teaser, which is
 * everything {@see NewsScrapeService} stores. It never sees the source's article
 * body, because that is never fetched. From those facts it writes an original
 * title, standfirst and body in this site's voice.
 *
 * That constraint is also the risk: a model handed two sentences and asked for
 * a few paragraphs will invent the difference. So the prompt forbids new
 * specifics, and the result still goes to a human before it can be published.
 * The approval gate is not decoration — nothing here ever sets `active`.
 *
 * ── Checked, not trusted ────────────────────────────────────────────────────
 *
 * "Do not reuse the source's wording" is an instruction a model can ignore, so
 * the output is measured against the source before it is saved: a title too
 * close to the headline, or too many of the source's six-word runs appearing
 * verbatim, rejects the rewrite. A rejection writes NOTHING — the row keeps
 * `body = NULL` and the next run tries again, with a temperature high enough
 * that the retry is a different draft rather than the same one.
 *
 * ── Not re-billing work already done ────────────────────────────────────────
 *
 * There is no status column and none was added. `body IS NULL` is the flag, and
 * it is an honest one: an article with no body has not been written yet.
 * Filling the body is what marks it done, so a second run selects nothing.
 *
 * ── The URL ─────────────────────────────────────────────────────────────────
 *
 * A draft's slug is provisional — the collector derived it from the SOURCE's
 * headline — and is replaced here with one built from our title. That is only
 * possible because dedup lives on `source_ref` rather than on the slug, and
 * only safe while the article has never been public. An article an editor has
 * already switched on keeps its URL, because changing it would 404 every link
 * already pointing there.
 *
 * ── Indexing ────────────────────────────────────────────────────────────────
 *
 * The model is handed this site's own category and casino pages and asked to
 * link one or two that genuinely relate. Every href it returns is checked
 * against that list, so it cannot invent a URL. A saved rewrite is indexable
 * (`noindex = false`) only when the body clears {@see NewsIndexGate}; otherwise
 * it stays noindex and the reason is reported. `active` is never touched —
 * publishing stays an editor's act.
 *
 * The model is also handed what THIS site knows that the source does not: the
 * casinos the story bears on, with their stored licence and withdrawal facts
 * ({@see NewsSiteFacts}), for a section that states them and links each one.
 *
 * ── Rewriting again ─────────────────────────────────────────────────────────
 *
 * `news:rewrite --all` selects scraped posts that already have a body too. The
 * source's headline and teaser were replaced by the first rewrite, so a repeat
 * works from this site's own title, standfirst and body — which is why the
 * checks against the source's wording are skipped for it.
 */
class NewsRewriteService
{
    /**
     * Google's Interactions API. Not `models/{model}:generateContent`: Google
     * answers that with 404 for keys created after it closed the older models
     * to new users, while this endpoint serves every current model.
     */
    private const string ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    /** The fields the model must return, enforced by the API, not only asked for. */
    private const array REPLY_SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'title'            => ['type' => 'string'],
            'excerpt'          => ['type' => 'string'],
            'body_html'        => ['type' => 'string'],
            'meta_title'       => ['type' => 'string'],
            'meta_description' => ['type' => 'string'],
        ],
        'required' => ['title', 'excerpt', 'body_html', 'meta_title', 'meta_description'],
    ];

    /**
     * What the body may contain. No images, no h1 — the page owns those. Links
     * only survive when they point at a page from {@see linkCatalog()}.
     */
    private const string BODY_TAGS = '<p><h2><ul><ol><li><strong><em><a>';

    /** Internal links kept per body; more reads as link stuffing. */
    private const int MAX_LINKS = 5;

    /** Casino pages offered to the model, highest-placed first. */
    private const int CATALOG_CASINOS = 20;

    /** Shorter than this is a stub, not an article. */
    private const int MIN_BODY_WORDS = 100;

    /** A title this similar to the source's headline (similar_text %) is the headline. */
    private const float MAX_TITLE_SIMILARITY = 75.0;

    /** Length of the word runs the overlap check compares. */
    private const int SHINGLE = 6;

    private const int TITLE_MAX = 90;

    private const int EXCERPT_MAX = 200;

    /**
     * What a results page shows of a title and a description — the same 60 and
     * 155 as the site's lib/seo/meta.ts, which fits them again on render.
     */
    private const int META_TITLE_MAX = 60;

    private const int META_DESCRIPTION_MAX = 155;

    private const int SLUG_MAX = 80;

    /**
     * High enough that a draft rejected for echoing the source comes back
     * DIFFERENT on the next run, instead of failing the same check forever.
     */
    private const float TEMPERATURE = 0.8;

    /** @var array<int, list<array{label: string, path: string}>> link catalog per site id, for one run */
    private array $catalogs = [];

    public function __construct(
        private readonly NewsCoverGenerator $covers,
        private readonly NewsSiteFacts $siteFacts,
    ) {}

    /**
     * Drafts waiting for original copy, newest story first. `news:scrape`
     * deletes the oldest day every day, so an oldest-first queue would spend
     * the model on drafts about to be deleted while today's news waited; with
     * a backlog, a `--limit` run now always covers the freshest stories.
     *
     * @return Collection<int, Article>
     */
    public function pending(int $limit, ?int $id = null, bool $all = false): Collection
    {
        $drafts = Article::query()
            ->ofType(Article::TYPE_NEWS)
            // Ingested only: a hand-written draft without a body has no facts
            // to rewrite, and sending it would bill a request for nothing.
            ->fromASource()
            // The guard. No body means no original copy has been written yet.
            // `--all` is the operator asking for everything to be written again.
            ->when(! $all, fn ($q) => $q->whereNull('body'))
            ->when($id !== null, fn ($q) => $q->whereKey($id))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            // Unlimited here so the per-site quota can skip a capped site and
            // still fill the run from the others. Bounded in practice: drafts
            // are a day or two of feed items, pruned daily by news:scrape.
            ->get();

        // `--id` / `--all` is the operator naming the work: no quota applies.
        if ($id !== null || $all) {
            return $drafts->take($limit)->values();
        }

        $remaining = $this->remainingQuotaBySite($drafts->pluck('site_id')->unique()->values()->all());

        return $drafts
            ->filter(function (Article $article) use (&$remaining): bool {
                if (($remaining[$article->site_id] ?? PHP_INT_MAX) <= 0) {
                    return false;
                }

                $remaining[$article->site_id] = ($remaining[$article->site_id] ?? PHP_INT_MAX) - 1;

                return true;
            })
            ->take($limit)
            ->values();
    }

    /**
     * How many more posts each site may have rewritten today.
     *
     * Volume is part of what makes generated news "scaled content", so each
     * site gets at most `news.rewrite.daily_per_site` a day; 0 = no cap. A
     * post counts on the day its text was written (`content_updated_at`, or
     * `updated_at` before that column's migration has run).
     *
     * @param  list<int>  $siteIds
     * @return array<int, int> site id => posts still allowed today (absent = no cap)
     */
    private function remainingQuotaBySite(array $siteIds): array
    {
        $cap = (int) config('news.rewrite.daily_per_site', 10);

        if ($cap <= 0 || $siteIds === []) {
            return [];
        }

        $column = SchemaGuard::hasColumn('articles', 'content_updated_at') ? 'content_updated_at' : 'updated_at';

        $used = Article::query()
            ->ofType(Article::TYPE_NEWS)
            ->fromASource()
            ->whereNotNull('body')
            ->whereIn('site_id', $siteIds)
            ->where($column, '>=', now()->startOfDay())
            ->selectRaw('site_id, COUNT(*) as used')
            ->groupBy('site_id')
            ->pluck('used', 'site_id');

        return collect($siteIds)
            ->mapWithKeys(fn (int $siteId): array => [$siteId => max(0, $cap - (int) ($used[$siteId] ?? 0))])
            ->all();
    }

    /**
     * Rewrite one draft. Never throws: every outcome is a status a run can
     * report and continue past.
     *
     * @return array{status: 'rewritten'|'rejected'|'failed', reason: ?string, slug: ?string, indexed: ?bool}
     */
    public function rewrite(Article $article, bool $dryRun = false): array
    {
        $sourceTitle = (string) $article->title;
        $sourceTeaser = (string) $article->excerpt;
        // A body means this is a repeat: the input is our own earlier copy.
        $again = trim((string) $article->body) !== '';
        $site = $article->site;
        $casinos = $site === null ? [] : $this->siteFacts->matching($site, $sourceTitle . ' ' . $sourceTeaser . ' ' . $article->body);
        $links = $site === null ? [] : $this->linkCatalog($site);
        $paths = array_values(array_unique([...array_column($links, 'path'), ...array_column($casinos, 'path')]));
        $facts = $this->facts($sourceTitle, $sourceTeaser, $again ? (string) $article->body : null, $links, $casinos);

        try {
            $written = $this->ask($facts, $paths);
        } catch (Throwable $e) {
            // The key travels in a header and is never part of the message;
            // only the status or the transport error is.
            Log::warning('news:rewrite request failed', [
                'article_id' => $article->id,
                'error'      => $e->getMessage(),
            ]);

            return $this->outcome('failed', $e->getMessage());
        }

        if (is_string($written)) {
            Log::warning('news:rewrite got an unusable reply', [
                'article_id' => $article->id,
                'error'      => $written,
            ]);

            return $this->outcome('failed', $written);
        }

        $reason = $this->rejection($sourceTitle, $sourceTeaser, $written, checkEcho: ! $again);

        if ($reason !== null) {
            // Not saved: the row keeps body = NULL and is retried next run.
            Log::info('news:rewrite rejected a draft', [
                'article_id' => $article->id,
                'reason'     => $reason,
            ]);

            return $this->outcome('rejected', $reason);
        }

        // The editor's note is never rewritten, but it is part of the page.
        $problems = $site === null ? ['the post has no site'] : NewsIndexGate::problems($written['body'], $site, $article->editor_note);

        // Under the bar: ask again with the exact shortfall, then — if only the
        // link is still missing — add one to a page the story bears on. No
        // words are ever added here: length can only come from the model,
        // under the same rules, or the post stays noindex.
        if ($site !== null && $problems !== []) {
            [$written, $problems] = $this->repair($article, $site, $facts, $paths, $written, $problems, $casinos, $links, checkEcho: ! $again);
        }

        $indexReason = $problems === [] ? null : 'kept noindex: ' . implode('; ', $problems);

        if ($dryRun) {
            return $this->outcome('rewritten', $indexReason, $this->slugFor($article, $written['title']), $problems === []);
        }

        try {
            $slug = $this->save($article, $written, noindex: $problems !== []);
        } catch (Throwable $e) {
            Log::warning('news:rewrite could not save', [
                'article_id' => $article->id,
                'error'      => $e->getMessage(),
            ]);

            return $this->outcome('failed', 'save failed: ' . $e->getMessage());
        }

        return $this->outcome('rewritten', $indexReason, $slug, $problems === []);
    }

    /**
     * The site's news-desk author — "Winpalack Team" for winpalack — created
     * the first time a rewrite needs it.
     *
     * Honest by construction: a rewritten post IS the site's editorial
     * output, so the desk is its author; no person is named as having written
     * it. The row is an ordinary author (Sites → Authors): an editor can give
     * it a photo and a fuller bio, rename it or hide it, and the next rewrites
     * keep using it by its slug. Null when the post has no site.
     */
    private function teamAuthorId(Article $article): ?int
    {
        $site = $article->site;

        if ($site === null) {
            return null;
        }

        $slug = Str::slug($site->name . ' team');

        $author = Author::query()->forSite($site->id)->where('slug', $slug)->first();

        if ($author === null) {
            try {
                $author = Author::query()->create([
                    'site_id'   => $site->id,
                    'name'      => Str::limit($site->name . ' Team', 120, ''),
                    'slug'      => $slug,
                    'role'      => 'News desk',
                    'short_bio' => Str::limit(
                        "The {$site->name} news desk. Stories are written from the report credited on each page and checked against this site's own casino records.",
                        300,
                        '',
                    ),
                    'visible'   => true,
                ]);
            } catch (Throwable) {
                // Another worker created it first (unique site + slug).
                $author = Author::query()->forSite($site->id)->where('slug', $slug)->first();
            }
        }

        return $author?->id;
    }

    /**
     * Bring a draft that missed NewsIndexGate up to the bar, automatically.
     *
     * 1. Up to `news.rewrite.repair_attempts` more requests, each handing the
     *    model its own previous draft and the exact shortfall ("246 words, at
     *    least 350"; "no link to a page of this site"). A repaired draft must
     *    pass every check the first one did — no source echo, nothing
     *    invented — and it replaces the kept draft only when it is closer to
     *    the bar (fewer problems, or as many and longer).
     * 2. If the ONLY problem left is the missing link and the site has a page
     *    to link, a closing "Related on this site" line links the most
     *    relevant one: a casino the story names first, else a site page. That
     *    is navigation, not content — it adds no claim to the article.
     *
     * Word count is never padded by code. A draft still short after the
     * repairs stays noindex, with its reason, as before.
     *
     * @param  array{title: string, excerpt: string, body: string, meta_title: ?string, meta_description: ?string}  $written
     * @param  list<string>  $problems
     * @param  list<string>  $paths
     * @param  list<array{name: string, path: string, facts: list<string>}>  $casinos
     * @param  list<array{label: string, path: string}>  $links
     * @return array{0: array{title: string, excerpt: string, body: string, meta_title: ?string, meta_description: ?string}, 1: list<string>}
     */
    private function repair(
        Article $article,
        Site $site,
        string $facts,
        array $paths,
        array $written,
        array $problems,
        array $casinos,
        array $links,
        bool $checkEcho,
    ): array {
        $attempts = max(0, (int) config('news.rewrite.repair_attempts', 2));
        $source = [(string) $article->title, (string) $article->excerpt];

        for ($i = 0; $i < $attempts && $problems !== []; $i++) {
            $needsWords = NewsIndexGate::wordCount($written['body']) + NewsIndexGate::wordCount((string) $article->editor_note) < NewsIndexGate::MIN_WORDS;
            $needsLink = ! NewsIndexGate::hasInternalLink($written['body'], $site);

            // Nothing a request can fix: only the link is missing and the site
            // offers no page to link.
            if (! $needsWords && (! $needsLink || $paths === [])) {
                break;
            }

            try {
                $retry = $this->ask($this->repairFacts($facts, $written, $needsWords, $needsLink && $paths !== []), $paths);
            } catch (Throwable $e) {
                Log::info('news:rewrite repair request failed', ['article_id' => $article->id, 'error' => $e->getMessage()]);

                break;
            }

            if (is_string($retry) || $this->rejection($source[0], $source[1], $retry, $checkEcho) !== null) {
                continue;
            }

            $retryProblems = NewsIndexGate::problems($retry['body'], $site, $article->editor_note);

            if (count($retryProblems) < count($problems)
                || (count($retryProblems) === count($problems) && NewsIndexGate::wordCount($retry['body']) > NewsIndexGate::wordCount($written['body']))) {
                [$written, $problems] = [$retry, $retryProblems];
            }
        }

        if ($problems !== [] && NewsIndexGate::wordCount($written['body']) + NewsIndexGate::wordCount((string) $article->editor_note) >= NewsIndexGate::MIN_WORDS) {
            $target = $this->relatedLink($casinos, $links);

            if ($target !== null) {
                $written['body'] .= '<p>Related on this site: <a href="' . e($target['path']) . '">' . e($target['label']) . '</a>.</p>';
                $problems = NewsIndexGate::problems($written['body'], $site, $article->editor_note);
            }
        }

        return [$written, $problems];
    }

    /**
     * The original facts plus the previous draft and what it lacks.
     *
     * @param  array{title: string, excerpt: string, body: string, meta_title: ?string, meta_description: ?string}  $written
     */
    private function repairFacts(string $facts, array $written, bool $needsWords, bool $needsLink): string
    {
        $fixes = [];

        if ($needsWords) {
            $fixes[] = sprintf(
                'The body has %d words; it must have 350 to 500. Add useful general context for players under the same rules — no new specifics, no repetition.',
                NewsIndexGate::wordCount($written['body']),
            );
        }

        if ($needsLink) {
            $fixes[] = 'The body links none of the listed site pages. Link one or two that genuinely relate, using the path exactly as listed.';
        }

        return $facts
            . "

Your previous draft did not meet the requirements. Rewrite it, keeping its facts and everything that was right:
"
            . json_encode($written, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "

Fix:
- " . implode("
- ", $fixes);
    }

    /**
     * The page a fallback link points at: a casino the story bears on, else
     * the first site page offered.
     *
     * @param  list<array{name: string, path: string, facts: list<string>}>  $casinos
     * @param  list<array{label: string, path: string}>  $links
     * @return array{label: string, path: string}|null
     */
    private function relatedLink(array $casinos, array $links): ?array
    {
        foreach ($casinos as $casino) {
            if (NewsIndexGate::isLinkablePath($casino['path'])) {
                return ['label' => $casino['name'] . ' review', 'path' => $casino['path']];
            }
        }

        foreach ($links as $link) {
            if (NewsIndexGate::isLinkablePath($link['path'])) {
                return $link;
            }
        }

        return null;
    }

    /**
     * Write the copy, the URL and the cover together.
     *
     * The cover is drawn inside the transaction so a failed save cleans up the
     * file it just wrote; the OLD cover is only deleted after the commit, so a
     * rollback can never leave the article pointing at a file that is gone.
     *
     * @param array{title: string, excerpt: string, body: string, meta_title: ?string, meta_description: ?string} $written
     */
    private function save(Article $article, array $written, bool $noindex): string
    {
        $oldSlug = (string) $article->slug;
        $oldCover = $article->hero_image_path;
        $newCover = null;

        try {
            $slug = DB::transaction(function () use ($article, $written, $noindex, $oldCover, &$newCover): string {
                $slug = $this->slugFor($article, $written['title']);
                $newCover = $this->covers->generate($slug, $written['title'], $written['excerpt']);

                $article->update([
                    'title'   => $written['title'],
                    'excerpt' => $written['excerpt'],
                    'body'    => $written['body'],
                    // Replaced with the copy they describe: a meta title kept
                    // from the old headline would advertise a different text.
                    // Null (the model sent nothing usable) falls back to the
                    // title and excerpt on the page.
                    'meta_title'       => $written['meta_title'],
                    'meta_description' => $written['meta_description'],
                    'slug'    => $slug,
                    // Null means drawing failed; keep the cover it already had.
                    'hero_image_path' => $newCover ?? $oldCover,
                    // Indexable only when the body clears NewsIndexGate (length
                    // + a link into this site), the same bar the admin applies.
                    'noindex' => $noindex,
                    // The byline: the site's own news desk, unless an editor
                    // already chose an author — a human choice is never
                    // replaced. The REVIEWER is never set here: "reviewed by"
                    // claims a person read it, which only that person can do.
                    'author_id' => $article->author_id ?? $this->teamAuthorId($article),
                    // `active` is deliberately absent: approval stays a human act.
                ]);

                return $slug;
            });
        } catch (Throwable $e) {
            if ($newCover !== null && $newCover !== $oldCover) {
                Storage::disk('public')->delete($newCover);
            }

            throw $e;
        }

        if ($newCover !== null && $oldCover !== null && $newCover !== $oldCover) {
            Storage::disk('public')->delete($oldCover);
        }

        SiteCache::flushSite($article->site_id);
        RevalidateNextJsSites::dispatch(
            array_values(array_unique(['news', 'news:' . $oldSlug, 'news:' . $slug])),
            [$article->site_id],
        );

        return $slug;
    }

    /**
     * Call the model. Returns the sanitised fields, or a reason string when the
     * reply was unusable; throws only on transport errors.
     *
     * @param  list<string>  $allowedPaths  internal hrefs the body may keep
     * @return array{title: string, excerpt: string, body: string}|string
     */
    private function ask(string $facts, array $allowedPaths): array|string
    {
        $response = Http::withHeaders([
            // A header, never the query string: URLs end up in access logs and
            // exception messages, headers do not.
            'x-goog-api-key' => (string) config('news.rewrite.api_key'),
        ])
            ->acceptJson()
            ->timeout((int) config('news.rewrite.timeout', 60))
            // 429 is routine on the free tier; three tries with a backoff long
            // enough for the per-minute quota to recover.
            ->retry(3, 8000, throw: false)
            ->post(self::ENDPOINT, [
                'model'              => (string) config('news.rewrite.model'),
                'system_instruction' => self::PROMPT,
                'input'              => $facts,
                'generation_config'  => ['temperature' => self::TEMPERATURE],
                'response_mime_type' => 'application/json',
                'response_format'    => self::REPLY_SCHEMA,
            ]);

        if (! $response->successful()) {
            // Google's own explanation ("no longer available", "high demand")
            // is what tells an operator what to change. It never holds the key.
            $why = $this->plain($response->json('error.message'));

            return 'model API returned HTTP ' . $response->status()
                . ($why !== '' ? ': ' . Str::limit($why, 200) : '');
        }

        // The text of every model_output step, joined; thought steps carry no
        // text and are skipped.
        $text = collect((array) $response->json('steps', []))
            ->filter(fn ($step) => is_array($step) && ($step['type'] ?? null) === 'model_output')
            ->flatMap(fn (array $step) => (array) ($step['content'] ?? []))
            ->map(fn ($part) => is_array($part) && ($part['type'] ?? 'text') === 'text' ? (string) ($part['text'] ?? '') : '')
            ->implode('');

        $json = json_decode(trim($text), true);

        if (! is_array($json)) {
            return 'reply was not valid JSON';
        }

        $title = $this->plain($json['title'] ?? null);
        $excerpt = $this->plain($json['excerpt'] ?? null);
        $body = is_string($json['body_html'] ?? null)
            ? $this->cleanBody($json['body_html'], $allowedPaths)
            : '';

        if ($title === '' || $excerpt === '' || $body === '') {
            return 'reply was missing title, excerpt or body_html';
        }

        return [
            'title'   => Str::limit($title, self::TITLE_MAX, ''),
            'excerpt' => Str::limit($excerpt, self::EXCERPT_MAX, ''),
            'body'    => $body,
            // Optional in effect: a missing or empty one is null, never a
            // reason to throw the article away.
            'meta_title'       => $this->fitted($this->plain($json['meta_title'] ?? null), self::META_TITLE_MAX),
            'meta_description' => $this->fitted($this->plain($json['meta_description'] ?? null), self::META_DESCRIPTION_MAX),
        ];
    }

    /**
     * The instructions. Every rule here exists because the output is published
     * under this site's name from two sentences of someone else's reporting.
     */
    private const string PROMPT = <<<'TXT'
    You write short news items for an online casino affiliate site whose
    editorial stance is consumer protection: licence checks, withdrawal terms,
    payment practice and safer-play tools. The audience is adult players.

    You will be given the facts of one news story: a headline and a teaser,
    and sometimes an earlier version of the article. Write an ORIGINAL item
    from those facts. You may also be given a list of this site's own pages.

    Rules:
    - Write from scratch. Do not reuse the source's sentences, phrasing or
      headline. Same facts, entirely your own words.
    - Use only the given facts. Never invent numbers, names, dates, quotes or
      outcomes. Brief, widely known general background is allowed, but it must
      add no new specifics.
    - 350 to 500 words in the body. Reach that length with useful context for
      players (how licensing, payments or safer-play rules like this usually
      work in general), never by repeating yourself or adding new specifics.
    - Neutral tone. No promotional language, no hype, no exclamation marks.
      Never imply gambling is a way to make money.
    - End the body with <h2>What this means for players</h2> followed by one
      short paragraph.
    - HTML in the body is limited to p, h2, ul, ol, li, strong, em and a. No
      images, no h1.
    - Link one or two of the listed site pages that genuinely relate to the
      story, as <a href="/path">natural anchor text</a>, using the path exactly
      as listed. Never link anything that is not on the list. If no page is
      listed, or none relates, use no links.
    - You may be given "Casinos on this site this story bears on", with facts
      from the site's own records. When given, add before the final section
      <h2>Casinos on our site this affects</h2> with one short paragraph or a
      list: state those facts for each casino, attributed to that casino, and
      link each casino by its path. Use only the facts listed for it, and do
      not say how the story will change any of them. Without that list, leave
      this section out.
    - Do not name the source publication; the page credits it separately.
    - title: at most 90 characters, and not the source's headline.
    - excerpt: one or two sentences, at most 200 characters.
    - meta_title: the search result title, 40 to 50 characters. Lead with the
      main subject (the regulator, operator or rule), say what happened, and
      do not copy the title word for word. No site name, no quotes, no
      clickbait, nothing the facts do not support.
    - meta_description: the search result snippet, 120 to 155 characters, one
      or two plain sentences: what happened and why it matters to players. Not
      a copy of the excerpt. No calls to action like "click" or "sign up".

    Reply with a single JSON object and nothing else:
    {"title": "...", "excerpt": "...", "body_html": "<p>...</p>",
     "meta_title": "...", "meta_description": "..."}
    TXT;

    /**
     * The facts, and only the facts: the row's headline and teaser, the
     * earlier body when this is a repeat, and the pages the body may link.
     *
     * @param list<array{label: string, path: string}> $links
     * @param list<array{name: string, path: string, facts: list<string>}> $casinos
     */
    private function facts(string $headline, string $teaser, ?string $earlierBody = null, array $links = [], array $casinos = []): string
    {
        $facts = "Headline: {$headline}\nTeaser: {$teaser}";

        if ($earlierBody !== null) {
            // A space for every tag, so paragraphs do not run into each other.
            $text = html_entity_decode(strip_tags(str_replace('<', ' <', $earlierBody)), ENT_QUOTES | ENT_HTML5);
            $facts .= "\nEarlier version of the article:\n" . trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        }

        if ($links !== []) {
            $facts .= "\n\nSite pages you may link:\n" . implode("\n", array_map(
                static fn (array $link): string => "- {$link['label']}: {$link['path']}",
                $links,
            ));
        }

        if ($casinos !== []) {
            $facts .= "\n\nCasinos on this site this story bears on (site records; state only these facts):\n"
                . implode("\n", array_map(
                    static fn (array $c): string => "- {$c['name']} ({$c['path']}): " . implode(' ', $c['facts']),
                    $casinos,
                ));
        }

        return $facts;
    }

    /**
     * This site's own pages the body may link: categories that list at least
     * one of its active casinos (the public listing's rule, so none of them
     * 404s) and its active casinos by placement. Paths match NewsIndexGate.
     *
     * @return list<array{label: string, path: string}>
     */
    private function linkCatalog(Site $site): array
    {
        return $this->catalogs[$site->id] ??= $this->buildLinkCatalog($site);
    }

    /** @return list<array{label: string, path: string}> */
    private function buildLinkCatalog(Site $site): array
    {
        $attached = static fn ($query) => $query->where('casinos.active', true)->attachedToSite($site->id);

        $categories = Category::query()
            ->whereHas('casinos', $attached)
            ->where('noindex', false)
            ->ordered()
            ->get(['name', 'slug'])
            ->map(static fn (Category $c): array => ['label' => $c->name . ' casinos', 'path' => '/categories/' . $c->slug]);

        $casinos = Casino::query()
            ->where('casinos.active', true)
            ->join('casino_site', 'casino_site.casino_id', '=', 'casinos.id')
            ->where('casino_site.site_id', $site->id)
            ->where('casino_site.active', true)
            ->orderBy('casino_site.position')
            ->limit(self::CATALOG_CASINOS)
            ->get(['casinos.name', 'casinos.slug'])
            ->map(static fn (Casino $c): array => ['label' => $c->name . ' review', 'path' => '/casinos/' . $c->slug]);

        return $categories->concat($casinos)
            ->filter(static fn (array $link): bool => NewsIndexGate::isLinkablePath($link['path']))
            ->values()
            ->all();
    }

    /**
     * The body reduced to the allowed tags, every attribute dropped, and each
     * link kept only when its href is one of the offered paths (at most
     * MAX_LINKS); any other link is unwrapped to its text.
     *
     * @param list<string> $allowedPaths
     */
    private function cleanBody(string $html, array $allowedPaths): string
    {
        $body = strip_tags($html, self::BODY_TAGS);

        // strip_tags keeps attributes; nothing but an href is ever wanted.
        $body = preg_replace('/<(p|h2|ul|ol|li|strong|em)\b[^>]*>/i', '<$1>', $body) ?? '';

        $kept = [];
        $body = preg_replace_callback(
            '/<a\b([^>]*)>(.*?)<\/a\s*>/is',
            function (array $m) use ($allowedPaths, &$kept): string {
                $text = strip_tags($m[2]);
                $href = preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $m[1], $h) === 1 ? rtrim(trim($h[2]), '/') : '';

                if (count($kept) >= self::MAX_LINKS || ! in_array($href, $allowedPaths, true) || trim($text) === '') {
                    return $text;
                }

                $kept[] = '<a href="' . e($href) . '">' . e(html_entity_decode($text, ENT_QUOTES | ENT_HTML5)) . '</a>';

                // A placeholder, so the stray-tag pass below cannot touch it.
                return "\x00" . (count($kept) - 1) . "\x00";
            },
            $body,
        ) ?? '';

        // Unclosed or stray <a> tags left after the pass carry no safe href.
        $body = preg_replace('/<\/?a\b[^>]*>/i', '', $body) ?? '';

        return trim(preg_replace_callback('/\x00(\d+)\x00/', static fn (array $m): string => $kept[(int) $m[1]], $body) ?? '');
    }

    /**
     * Why this rewrite must not be saved, or null when it may.
     *
     * `checkEcho` off for a repeat rewrite: the "source" is then this site's
     * own earlier copy, and resembling it is not copying anyone.
     *
     * @param array{title: string, excerpt: string, body: string} $written
     */
    private function rejection(string $headline, string $teaser, array $written, bool $checkEcho = true): ?string
    {
        $words = count($this->words(strip_tags($written['body'])));

        if ($words < self::MIN_BODY_WORDS) {
            return "body is {$words} words, under " . self::MIN_BODY_WORDS;
        }

        if (! $checkEcho) {
            return null;
        }

        similar_text(mb_strtolower($headline), mb_strtolower($written['title']), $similarity);

        if ($similarity > self::MAX_TITLE_SIMILARITY) {
            return sprintf('title is %.0f%% similar to the source headline', $similarity);
        }

        $overlap = $this->overlap(
            $headline . ' ' . $teaser,
            $written['title'] . ' ' . $written['excerpt'] . ' ' . strip_tags($written['body'])
                . ' ' . $written['meta_title'] . ' ' . $written['meta_description'],
        );
        $max = (float) config('news.rewrite.max_overlap', 0.15);

        if ($overlap > $max) {
            return sprintf('%.0f%% of the source\'s %d-word runs reused verbatim (max %.0f%%)', $overlap * 100, self::SHINGLE, $max * 100);
        }

        return null;
    }

    /**
     * Share of the source's six-word runs that appear verbatim in the output.
     *
     * Runs rather than single words, because the facts legitimately repeat the
     * source's nouns; it is a reused PHRASE that marks copying. Zero when the
     * source is too short to have a single run.
     */
    private function overlap(string $source, string $output): float
    {
        $runs = $this->shingles($this->words($source));

        if ($runs === []) {
            return 0.0;
        }

        $found = array_intersect_key($runs, $this->shingles($this->words($output)));

        return count($found) / count($runs);
    }

    /**
     * Lowercase Unicode words — letters and digits only, so punctuation and
     * markup can never make a copied phrase look different.
     *
     * @return list<string>
     */
    private function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5)), $m);

        return $m[0];
    }

    /**
     * Distinct runs, as keys, so overlap counts each source phrase once.
     *
     * @param list<string> $words
     * @return array<string, true>
     */
    private function shingles(array $words): array
    {
        $runs = [];

        for ($i = 0, $n = count($words) - self::SHINGLE; $i <= $n; $i++) {
            $runs[implode(' ', array_slice($words, $i, self::SHINGLE))] = true;
        }

        return $runs;
    }

    /**
     * The slug for the rewritten article.
     *
     * A live article keeps its own — see the class note. Otherwise one from our
     * title, cut at a word boundary the way {@see NewsFeedParser} cuts the
     * provisional one, and unique within the site. The suffix matters: two
     * feeds covering one story can easily yield the same rewritten title.
     * withTrashed, because a soft-deleted row still holds the unique index.
     */
    private function slugFor(Article $article, string $title): string
    {
        if ($article->active) {
            return (string) $article->slug;
        }

        $base = $this->trimmedSlug($title) ?: (string) $article->slug;
        $slug = $base;
        $n = 2;

        while (
            Article::withTrashed()
                ->where('site_id', $article->site_id)
                ->where('slug', $slug)
                ->whereKeyNot($article->getKey())
                ->exists()
            // A deleted post's URL answers 410; a new post must not take it.
            || DeletedNewsSlug::holds((int) $article->site_id, $slug)
        ) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }

    private function trimmedSlug(string $title): string
    {
        $slug = Str::slug($title);

        if (strlen($slug) <= self::SLUG_MAX) {
            return $slug;
        }

        $slug = substr($slug, 0, self::SLUG_MAX);
        $cut = strrpos($slug, '-');

        return ($cut !== false && $cut > 40) ? substr($slug, 0, $cut) : rtrim($slug, '-');
    }

    /**
     * Cut at the last whole word that fits, trailing punctuation dropped; null
     * when there is nothing to keep. No ellipsis: the site adds its own when
     * it has to shorten again.
     */
    private function fitted(string $text, int $max): ?string
    {
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max + 1);
        $head = preg_replace(['/\s+\S*$/u', '/[\s,;:.\x{2014}\x{2013}-]+$/u'], '', $cut) ?? '';

        return $head !== '' ? $head : mb_substr($text, 0, $max);
    }

    private function plain(mixed $value): string
    {
        return is_string($value)
            // Decoded BEFORE stripping, so an encoded tag cannot survive as one.
            ? trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5))) ?? '')
            : '';
    }

    /**
     * `indexed` is null unless the post was (or, on a dry run, would be) saved;
     * a saved post's `reason` says why it was kept noindex, if it was.
     *
     * @return array{status: 'rewritten'|'rejected'|'failed', reason: ?string, slug: ?string, indexed: ?bool}
     */
    private function outcome(string $status, ?string $reason, ?string $slug = null, ?bool $indexed = null): array
    {
        return ['status' => $status, 'reason' => $reason, 'slug' => $slug, 'indexed' => $indexed];
    }
}
