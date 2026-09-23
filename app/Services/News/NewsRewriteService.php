<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Article;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Rewrites scraped facts into this site's own editorial.
 *
 * ── What "rewrite" means here, precisely ────────────────────────────────────
 *
 * The model is given THREE things: the source's headline, its teaser, and its
 * categories. It never sees the source's article body, because that is never
 * fetched or stored. From those facts it writes an original title and an
 * original body in this site's voice.
 *
 * That constraint is also the risk: a model handed two sentences and asked for
 * six paragraphs will invent the difference. So the prompt forbids inventing
 * figures, dates, quotes and named parties beyond the supplied facts, asks for
 * a short piece rather than a padded one, and the result still goes to a human
 * before it can be published. The approval gate is not decoration.
 *
 * ── The URL ─────────────────────────────────────────────────────────────────
 *
 * The slug is regenerated here from the title this service writes, replacing
 * the provisional one the collector derived from the source's headline. That is
 * only safe because the article has never been public — hidden and noindex from
 * the moment it was created — and only possible because dedup lives on
 * `source_ref` rather than on the slug.
 *
 * ── Not re-billing work already done ────────────────────────────────────────
 *
 * There is no status column and none was added. `body IS NULL` is the flag, and
 * it is an honest one rather than a marker invented for the purpose: an article
 * with no body has not been written yet. Filling the body is what marks it
 * done, so a second run selects nothing and spends nothing.
 *
 * A failure therefore leaves the row untouched and it is retried on the next
 * run. That is deliberate — a thin teaser may be the reason, and feeds often
 * fill those in later — but it does mean a permanently unusable item is
 * retried. `--limit` bounds what any single run can cost.
 */
class NewsRewriteService
{
    private const string ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const string API_VERSION = '2023-06-01';

    /**
     * Rewrite up to `$limit` scraped drafts.
     *
     * @return array{rewritten: int, failed: int, skipped: int}
     */
    public function run(int $limit, ?callable $report = null): array
    {
        $say = $report ?? static fn (string $line, string $level = 'line') => null;
        $out = ['rewritten' => 0, 'failed' => 0, 'skipped' => 0];

        $key = (string) config('services.anthropic.key');

        if (trim($key) === '') {
            // No fallback and no silent no-op: a missing key is a configuration
            // problem, and the run says so rather than reporting "0 rewritten"
            // as though there had been nothing to do.
            $say('ANTHROPIC_API_KEY is not set — refusing to run. Add it to .env.', 'error');

            return $out;
        }

        $site = Site::where('slug', config('news.site_slug'))->first();

        if ($site === null) {
            $say('No site with slug ' . config('news.site_slug') . '.', 'error');

            return $out;
        }

        $pending = Article::query()
            ->where('site_id', $site->id)
            ->ofType(Article::TYPE_NEWS)
            // The guard. No body means no original copy has been written yet.
            ->whereNull('body')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        if ($pending->isEmpty()) {
            $say('Nothing to rewrite.', 'info');

            return $out;
        }

        $touched = [];

        foreach ($pending as $article) {
            try {
                $written = $this->rewrite($article, $key);

                if ($written === null) {
                    $out['failed']++;
                    $say("  ! {$article->slug} — left as a draft for the next run", 'warn');

                    continue;
                }

                // The URL is built from OUR title, not the source's headline.
                // Safe because the article has never been public — it is
                // hidden and noindex until an editor approves it — and it is
                // only possible because dedup lives on `source_ref` rather
                // than on the slug.
                $written['slug'] = $this->slugFor($article, $written['title']);

                $article->update($written);
                $out['rewritten']++;
                $touched[] = $article->slug;
                $say("  ~ {$article->slug} — rewritten as: {$written['title']}", 'info');
            } catch (Throwable $e) {
                $out['failed']++;
                $say("  ! {$article->slug} — " . $e->getMessage(), 'error');
                // The key is never part of the message; only the endpoint and
                // the model are, and neither is a secret.
                Log::warning('news:rewrite failed for one article', [
                    'slug'  => $article->slug,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($touched !== []) {
            SiteCache::flushSite($site->id);
            RevalidateNextJsSites::dispatch(
                ['news', ...array_map(static fn (string $s) => 'news:' . $s, $touched)],
                [$site->id],
            );
        }

        return $out;
    }

    /**
     * A slug from the rewritten title, unique within this site.
     *
     * The collision suffix matters more than it looks: two feeds covering the
     * same story can easily yield the same rewritten title, and the second one
     * would otherwise fail the `unique(site_id, slug)` index and be reported as
     * a rewrite failure rather than what it is.
     */
    private function slugFor(Article $article, string $title): string
    {
        $base = Str::limit(Str::slug($title), 80, '') ?: $article->slug;
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

    /**
     * One article. Null when the model returned nothing usable.
     *
     * @return array<string, mixed>|null
     */
    private function rewrite(Article $article, string $key): ?array
    {
        $response = Http::withHeaders([
            // Sent as a header and never logged, echoed or put in a message.
            'x-api-key'         => $key,
            'anthropic-version' => self::API_VERSION,
            'content-type'      => 'application/json',
        ])
            ->timeout((int) config('news.rewrite.timeout', 60))
            ->post(self::ENDPOINT, [
                'model'      => (string) config('news.rewrite.model'),
                'max_tokens' => (int) config('news.rewrite.max_tokens', 2000),
                'system'     => $this->system(),
                'messages'   => [[
                    'role'    => 'user',
                    'content' => $this->prompt($article),
                ]],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Anthropic API returned HTTP ' . $response->status());
        }

        $text = (string) ($response->json('content.0.text') ?? '');

        return $this->fieldsFrom($text);
    }

    private function system(): string
    {
        return <<<'TXT'
        You write short news items for an online casino affiliate site whose
        editorial stance is consumer protection: licence checks, withdrawal
        terms, payment practice and safer-play tools. The audience is adult
        players deciding where to play, not industry insiders.

        You will be given the facts of one news story: a headline and a teaser.
        Write an ORIGINAL item from those facts and nothing else.

        Absolute rules:
        - Use ONLY the supplied facts. Do not add figures, dates, percentages,
          quotes, company names, regulators or outcomes that are not in them.
        - If the facts do not support an interesting item, say so by returning
          an empty title. Do not pad.
        - Never reuse the source's phrasing. Same facts, your own sentences.
        - No hype, no "exciting news", no exclamation marks, no invented quotes.
        - British English. Plain, factual sentences.
        - Never promise winnings or imply gambling is a way to make money.

        Reply with a single JSON object and nothing else:
        {
          "title": "under 60 characters, specific, not the source's headline",
          "excerpt": "one sentence standfirst, under 200 characters",
          "body": "<p>...</p> 2-4 short paragraphs of simple HTML, only <p>, <h2>, <ul>, <li>, <strong>",
          "meta_title": "under 60 characters",
          "meta_description": "under 155 characters"
        }
        TXT;
    }

    /**
     * The facts, and only the facts.
     *
     * Two of them, because two is what the row holds. The source's topic labels
     * are parsed but never stored — there is no column for them — so the model
     * is not told about a taxonomy it cannot see. Telling it "topic labels:
     * none given" would invite it to guess one.
     */
    private function prompt(Article $article): string
    {
        return "Headline: {$article->title}\n"
            . "Teaser: {$article->excerpt}\n\n"
            . 'Write the item as specified.';
    }

    /**
     * Pull the fields out of the reply.
     *
     * Tolerant of a model that wraps its JSON in prose or a code fence, and
     * strict about the result: anything missing the two fields that matter is
     * rejected rather than written half-formed.
     *
     * @return array<string, mixed>|null
     */
    private function fieldsFrom(string $text): ?array
    {
        $json = null;

        if (preg_match('/\{.*\}/s', $text, $m) === 1) {
            $json = json_decode($m[0], true);
        }

        if (! is_array($json)) {
            return null;
        }

        $title = trim((string) ($json['title'] ?? ''));
        $body  = trim((string) ($json['body'] ?? ''));

        // An empty title is how the prompt asks the model to decline. Honour it
        // rather than publishing whatever it produced anyway.
        if ($title === '' || $body === '') {
            return null;
        }

        $excerpt = trim((string) ($json['excerpt'] ?? ''));

        return [
            'title'   => mb_substr($title, 0, 255),
            'excerpt' => $excerpt === '' ? null : mb_substr($excerpt, 0, 500),
            'body'    => $body,
            'meta_title'       => mb_substr(trim((string) ($json['meta_title'] ?? $title)), 0, 255),
            'meta_description' => mb_substr(trim((string) ($json['meta_description'] ?? $excerpt)), 0, 500),

            // The copy is original now, so the article may be indexed. It was
            // set while the row still carried the source's headline and teaser
            // — see NewsScrapeService.
            'noindex' => false,

            // The slug is set by the caller from this title — see slugFor().
            // `active` is deliberately absent: approval stays a human act.
        ];
    }
}
