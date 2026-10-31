<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Article;
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
            'title'     => ['type' => 'string'],
            'excerpt'   => ['type' => 'string'],
            'body_html' => ['type' => 'string'],
        ],
        'required' => ['title', 'excerpt', 'body_html'],
    ];

    /** What the body may contain. No links, no images, no h1 — the page owns those. */
    private const string BODY_TAGS = '<p><h2><ul><ol><li><strong><em>';

    /** Shorter than this is a stub, not an article. */
    private const int MIN_BODY_WORDS = 100;

    /** A title this similar to the source's headline (similar_text %) is the headline. */
    private const float MAX_TITLE_SIMILARITY = 75.0;

    /** Length of the word runs the overlap check compares. */
    private const int SHINGLE = 6;

    private const int TITLE_MAX = 90;

    private const int EXCERPT_MAX = 200;

    private const int SLUG_MAX = 80;

    /**
     * High enough that a draft rejected for echoing the source comes back
     * DIFFERENT on the next run, instead of failing the same check forever.
     */
    private const float TEMPERATURE = 0.8;

    public function __construct(
        private readonly NewsCoverGenerator $covers,
    ) {}

    /**
     * Drafts waiting for original copy, newest story first. `news:scrape`
     * deletes the oldest day every day, so an oldest-first queue would spend
     * the model on drafts about to be deleted while today's news waited; with
     * a backlog, a `--limit` run now always covers the freshest stories.
     *
     * @return Collection<int, Article>
     */
    public function pending(int $limit, ?int $id = null): Collection
    {
        return Article::query()
            ->ofType(Article::TYPE_NEWS)
            // Ingested only: a hand-written draft without a body has no facts
            // to rewrite, and sending it would bill a request for nothing.
            ->fromASource()
            // The guard. No body means no original copy has been written yet.
            ->whereNull('body')
            ->when($id !== null, fn ($q) => $q->whereKey($id))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Rewrite one draft. Never throws: every outcome is a status a run can
     * report and continue past.
     *
     * @return array{status: 'rewritten'|'rejected'|'failed', reason: ?string, slug: ?string}
     */
    public function rewrite(Article $article, bool $dryRun = false): array
    {
        $sourceTitle = (string) $article->title;
        $sourceTeaser = (string) $article->excerpt;

        try {
            $written = $this->ask($sourceTitle, $sourceTeaser);
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

        $reason = $this->rejection($sourceTitle, $sourceTeaser, $written);

        if ($reason !== null) {
            // Not saved: the row keeps body = NULL and is retried next run.
            Log::info('news:rewrite rejected a draft', [
                'article_id' => $article->id,
                'reason'     => $reason,
            ]);

            return $this->outcome('rejected', $reason);
        }

        if ($dryRun) {
            return $this->outcome('rewritten', null, $this->slugFor($article, $written['title']));
        }

        try {
            $slug = $this->save($article, $written);
        } catch (Throwable $e) {
            Log::warning('news:rewrite could not save', [
                'article_id' => $article->id,
                'error'      => $e->getMessage(),
            ]);

            return $this->outcome('failed', 'save failed: ' . $e->getMessage());
        }

        return $this->outcome('rewritten', null, $slug);
    }

    /**
     * Write the copy, the URL and the cover together.
     *
     * The cover is drawn inside the transaction so a failed save cleans up the
     * file it just wrote; the OLD cover is only deleted after the commit, so a
     * rollback can never leave the article pointing at a file that is gone.
     *
     * @param array{title: string, excerpt: string, body: string} $written
     */
    private function save(Article $article, array $written): string
    {
        $oldSlug = (string) $article->slug;
        $oldCover = $article->hero_image_path;
        $newCover = null;

        try {
            $slug = DB::transaction(function () use ($article, $written, $oldCover, &$newCover): string {
                $slug = $this->slugFor($article, $written['title']);
                $newCover = $this->covers->generate($slug, $written['title'], $written['excerpt']);

                $article->update([
                    'title'   => $written['title'],
                    'excerpt' => $written['excerpt'],
                    'body'    => $written['body'],
                    'slug'    => $slug,
                    // Null means drawing failed; keep the cover it already had.
                    'hero_image_path' => $newCover ?? $oldCover,
                    // The copy is original now, so the page may be indexed. It
                    // was set while the row still held the source's wording.
                    'noindex' => false,
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
     * @return array{title: string, excerpt: string, body: string}|string
     */
    private function ask(string $headline, string $teaser): array|string
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
                'input'              => $this->facts($headline, $teaser),
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
            ? trim(strip_tags($json['body_html'], self::BODY_TAGS))
            : '';

        if ($title === '' || $excerpt === '' || $body === '') {
            return 'reply was missing title, excerpt or body_html';
        }

        return [
            'title'   => Str::limit($title, self::TITLE_MAX, ''),
            'excerpt' => Str::limit($excerpt, self::EXCERPT_MAX, ''),
            'body'    => $body,
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

    You will be given the facts of one news story: a headline and a teaser.
    Write an ORIGINAL item from those facts.

    Rules:
    - Write from scratch. Do not reuse the source's sentences, phrasing or
      headline. Same facts, entirely your own words.
    - Use only the given facts. Never invent numbers, names, dates, quotes or
      outcomes. Brief, widely known general background is allowed, but it must
      add no new specifics.
    - 150 to 280 words in the body.
    - Neutral tone. No promotional language, no hype, no exclamation marks.
      Never imply gambling is a way to make money.
    - End the body with <h2>What this means for players</h2> followed by one
      short paragraph.
    - HTML in the body is limited to p, h2, ul, ol, li, strong and em. No
      links, no images, no h1.
    - Do not name the source publication; the page credits it separately.
    - title: at most 90 characters, and not the source's headline.
    - excerpt: one or two sentences, at most 200 characters.

    Reply with a single JSON object and nothing else:
    {"title": "...", "excerpt": "...", "body_html": "<p>...</p>"}
    TXT;

    /** The facts, and only the facts — two of them, because two is what the row holds. */
    private function facts(string $headline, string $teaser): string
    {
        return "Headline: {$headline}\nTeaser: {$teaser}";
    }

    /**
     * Why this rewrite must not be saved, or null when it may.
     *
     * @param array{title: string, excerpt: string, body: string} $written
     */
    private function rejection(string $headline, string $teaser, array $written): ?string
    {
        $words = count($this->words(strip_tags($written['body'])));

        if ($words < self::MIN_BODY_WORDS) {
            return "body is {$words} words, under " . self::MIN_BODY_WORDS;
        }

        similar_text(mb_strtolower($headline), mb_strtolower($written['title']), $similarity);

        if ($similarity > self::MAX_TITLE_SIMILARITY) {
            return sprintf('title is %.0f%% similar to the source headline', $similarity);
        }

        $overlap = $this->overlap(
            $headline . ' ' . $teaser,
            $written['title'] . ' ' . $written['excerpt'] . ' ' . strip_tags($written['body']),
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

    private function plain(mixed $value): string
    {
        return is_string($value)
            // Decoded BEFORE stripping, so an encoded tag cannot survive as one.
            ? trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5))) ?? '')
            : '';
    }

    /** @return array{status: 'rewritten'|'rejected'|'failed', reason: ?string, slug: ?string} */
    private function outcome(string $status, ?string $reason, ?string $slug = null): array
    {
        return ['status' => $status, 'reason' => $reason, 'slug' => $slug];
    }
}
