<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Site;
use App\Services\News\NewsFeedParser;
use App\Services\News\NewsRewriteService;
use App\Services\News\NewsScrapeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * News ingestion: the collector, the rewriter, and the boundaries on both.
 *
 * Every test runs against a saved fixture rather than a live feed. The suite
 * calls Http::preventStrayRequests(), so a test that reached the internet would
 * fail — which is the point: the parser's behaviour must be reproducible in
 * January when a publisher has reordered their feed.
 */
class NewsScrapeTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // The cover generator writes to the `public` disk. Without this the
        // suite drops SVGs into storage/app/public/news on the developer's
        // machine, named after whatever is in the fixture.
        Storage::fake('public');

        $this->site = Site::factory()->create([
            'slug'         => 'winpalack',
            'name'         => 'Winpalack',
            'domain'       => 'winpalack.test',
            'news_enabled' => true,
        ]);

        config([
            'news.site_slug' => 'winpalack',
            'news.delay_seconds' => 0,
            'news.sources' => [[
                'key'     => 'fixture',
                'name'    => 'Fixture Feed',
                'url'     => 'https://example.test/feed/',
                'enabled' => true,
            ]],
        ]);
    }

    private function feed(): string
    {
        return file_get_contents(base_path('tests/Fixtures/news/feed.xml'));
    }

    /** robots.txt allows everything; the feed returns the fixture. */
    private function fakeFeed(?string $robots = null): void
    {
        Http::fake([
            'example.test/robots.txt' => Http::response($robots ?? "User-agent: *\nDisallow:\n"),
            'example.test/feed/'      => Http::response($this->feed(), 200, ['Content-Type' => 'application/rss+xml']),
        ]);
    }

    // ── the parser ───────────────────────────────────────────────────────────

    public function test_the_parser_takes_the_facts_and_skips_what_it_cannot_use(): void
    {
        $parsed = app(NewsFeedParser::class)->parse($this->feed(), 'fixture', 'Fixture Feed');

        // Three real items; three deliberately broken ones are refused.
        $this->assertCount(3, $parsed['items']);
        $this->assertCount(3, $parsed['skipped']);

        $first = $parsed['items'][0];
        foreach (['source_id', 'url', 'headline', 'teaser', 'published_at', 'slug'] as $field) {
            $this->assertArrayHasKey($field, $first);
            $this->assertNotEmpty($first[$field], "{$field} must be populated");
        }

        // The id is the stable WordPress post id, not a hash of the wording.
        $this->assertMatchesRegularExpression('/^\d+$/', $first['source_id']);
    }

    /**
     * THE COPYRIGHT BOUNDARY. The fixture contains the publisher's full article
     * body in <content:encoded>; nothing the parser returns may carry it.
     */
    public function test_the_parser_never_returns_the_publishers_article_body(): void
    {
        $this->assertStringContainsString('<content:encoded', $this->feed(), 'the fixture must contain a body to be a real test');

        $parsed = app(NewsFeedParser::class)->parse($this->feed(), 'fixture', 'Fixture Feed');
        $serialised = json_encode($parsed['items']);

        foreach (NewsFeedParser::EXCLUDED as $field) {
            $this->assertStringNotContainsString($field, (string) $serialised);
        }

        // A teaser is a summary; a body is not. Nothing parsed is body-length.
        foreach ($parsed['items'] as $item) {
            $this->assertLessThan(1000, mb_strlen($item['teaser']), 'a teaser this long is the article body');
        }
    }

    public function test_a_document_that_is_not_a_feed_is_reported_rather_than_read_as_empty(): void
    {
        $parsed = app(NewsFeedParser::class)->parse('<html><body>not a feed</body></html>', 'fixture', 'Fixture Feed');

        $this->assertSame([], $parsed['items']);
        // "No items" and "this stopped being a feed" must not look the same.
        $this->assertNotEmpty($parsed['skipped']);
    }

    // ── the collector ────────────────────────────────────────────────────────

    public function test_scraped_items_enter_the_existing_draft_flow(): void
    {
        $this->fakeFeed();

        app(NewsScrapeService::class)->run();

        $articles = Article::where('site_id', $this->site->id)->ofType(Article::TYPE_NEWS)->get();
        $this->assertCount(3, $articles);

        foreach ($articles as $article) {
            // Nothing is publicly visible: scopeVisible() needs active AND a date.
            $this->assertFalse($article->active, 'a scraped row must never be live');
            $this->assertFalse($article->featured);
            // The source's real date, so the feed's chronology is truthful.
            $this->assertNotNull($article->published_at);
            // No body yet — that is the "not rewritten" flag.
            $this->assertNull($article->body);
            // The source's taxonomy is not this site's.
            $this->assertNull($article->news_category_id);

            // Provenance: which publication reported the facts, and where.
            $this->assertNotNull($article->source_ref);
            $this->assertSame('Fixture Feed', $article->source_name);
            $this->assertStringStartsWith('http', (string) $article->source_url);
            // Namespaced by source key, so two publishers reusing a post id
            // cannot collide.
            $this->assertStringStartsWith('fixture:', $article->source_ref);
            // Still carrying the source's headline and teaser as the
            // rewriter's input, so it must not be indexable even if an editor
            // publishes it early by mistake.
            $this->assertTrue($article->noindex);
            // A cover is DRAWN for every item. The source's photographs are
            // never copied — see NewsCoverGenerator.
            $this->assertNotNull($article->hero_image_path);
            $this->assertStringStartsWith('news/', $article->hero_image_path);
            Storage::disk('public')->assertExists($article->hero_image_path);
        }

        $this->assertSame(
            0,
            Article::where('site_id', $this->site->id)->ofType(Article::TYPE_NEWS)->visible()->count(),
            'nothing scraped may reach the public API before a person approves it',
        );
    }

    public function test_running_twice_creates_no_duplicates(): void
    {
        $this->fakeFeed();

        app(NewsScrapeService::class)->run();
        $first = Article::orderBy('id')->pluck('id')->all();

        app(NewsScrapeService::class)->run();

        $this->assertSame($first, Article::orderBy('id')->pluck('id')->all());
        $this->assertCount(3, $first);
    }

    /** The dedup key is the slug, so a hand-written clash must SKIP, not overwrite. */
    /**
     * The point of keying on the publisher's id rather than on wording: a
     * source that edits its own headline after publishing is the same item, and
     * the slug-based dedup this replaced would have taken it twice.
     */
    public function test_an_item_is_recognised_even_after_its_headline_changes(): void
    {
        $this->fakeFeed();
        app(NewsScrapeService::class)->run();

        $article = Article::orderBy('id')->firstOrFail();
        $article->update(['title' => 'A completely different headline', 'slug' => 'a-completely-different-headline']);

        app(NewsScrapeService::class)->run();

        $this->assertSame(3, Article::count(), 'the same source id must not be taken twice');
    }

    public function test_an_existing_article_with_the_same_slug_is_never_overwritten(): void
    {
        $this->fakeFeed();

        $parsed = app(NewsFeedParser::class)->parse($this->feed(), 'fixture', 'Fixture Feed');
        $slug = $parsed['items'][0]['slug'];

        $mine = Article::create([
            'site_id' => $this->site->id,
            'type'    => Article::TYPE_NEWS,
            'title'   => 'Written by a person',
            'slug'    => $slug,
            'body'    => '<p>Mine.</p>',
            'active'  => true,
            'published_at' => now()->subDay(),
        ]);

        app(NewsScrapeService::class)->run();

        $mine->refresh();
        $this->assertSame('Written by a person', $mine->title);
        $this->assertSame('<p>Mine.</p>', $mine->body);
        $this->assertTrue($mine->active);
        $this->assertSame(3, Article::count(), 'the clash must be skipped, not duplicated');
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->fakeFeed();

        $totals = app(NewsScrapeService::class)->run(dryRun: true);

        $this->assertSame(3, $totals['created'], 'a dry run still reports what it would do');
        $this->assertSame(0, Article::count());
    }

    public function test_a_source_that_disallows_us_in_robots_is_not_fetched(): void
    {
        $this->fakeFeed("User-agent: *\nDisallow: /feed/\n");

        $totals = app(NewsScrapeService::class)->run();

        $this->assertSame(0, $totals['created']);
        $this->assertSame(1, $totals['failed']);
        $this->assertSame(0, Article::count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/feed/'));
    }

    public function test_a_directive_aimed_at_another_crawler_does_not_stop_us(): void
    {
        // We are not Googlebot, so its rules say nothing about us.
        $this->fakeFeed("User-agent: Googlebot\nDisallow: /feed/\n\nUser-agent: *\nDisallow: /private\n");

        $this->assertSame(3, app(NewsScrapeService::class)->run()['created']);
    }

    public function test_a_failing_feed_does_not_throw(): void
    {
        Http::fake([
            'example.test/robots.txt' => Http::response("User-agent: *\nDisallow:\n"),
            'example.test/feed/'      => Http::response('', 503),
        ]);

        $totals = app(NewsScrapeService::class)->run();

        $this->assertSame(0, $totals['created']);
        $this->assertSame(1, $totals['failed']);
    }

    // ── the rewriter ─────────────────────────────────────────────────────────

    private function fakeAnthropic(array $fields): void
    {
        config(['services.anthropic.key' => 'test-key']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode($fields)]],
            ]),
        ]);
    }

    private function draft(): Article
    {
        return Article::create([
            'site_id' => $this->site->id,
            'type'    => Article::TYPE_NEWS,
            'title'   => 'Regulator renews eight operator licences',
            'slug'    => 'regulator-renews-eight-operator-licences',
            'excerpt' => 'The regulator has renewed the licences of eight operators following its periodic review.',
            'body'    => null,
            'active'  => false,
            'noindex' => true,
            'source_ref'  => 'igamingbusiness:1',
            'source_name' => 'iGaming Business',
            'source_url'  => 'https://igamingbusiness.com/example/',
            'published_at' => now()->subDay(),
        ]);
    }

    public function test_the_rewriter_replaces_the_copy_but_not_the_approval_state(): void
    {
        $draft = $this->draft();
        $this->fakeAnthropic([
            'title'   => 'Eight licences renewed after review',
            'excerpt' => 'What the renewal means for players choosing where to deposit.',
            'body'    => '<p>Original copy.</p><p>Second paragraph.</p>',
            'meta_title'       => 'Eight licences renewed',
            'meta_description' => 'What the renewal means for players.',
        ]);

        $totals = app(NewsRewriteService::class)->run(10);

        $draft->refresh();
        $this->assertSame(1, $totals['rewritten']);
        $this->assertSame('Eight licences renewed after review', $draft->title);
        $this->assertStringContainsString('Original copy.', (string) $draft->body);
        $this->assertNotNull($draft->meta_title);
        // Derived on save, never supplied by the model.
        $this->assertNotNull($draft->read_minutes);

        // Original copy now, so it may be indexed.
        $this->assertFalse($draft->noindex);

        // Approval stays a human act — the one thing a rewrite must not touch.
        $this->assertFalse($draft->active);

        // The URL is rebuilt from OUR title, replacing the provisional slug the
        // collector derived from the source's headline. Only possible because
        // dedup lives on `source_ref`, and safe because the article has never
        // been public.
        $this->assertSame('eight-licences-renewed-after-review', $draft->slug);
        $this->assertSame('igamingbusiness:1', $draft->source_ref, 'the dedup key is never rewritten');
    }

    public function test_an_article_already_rewritten_is_not_sent_again(): void
    {
        $this->draft()->update(['body' => '<p>Already written.</p>']);
        $this->fakeAnthropic(['title' => 'Should never be used', 'body' => '<p>x</p>']);

        $totals = app(NewsRewriteService::class)->run(10);

        $this->assertSame(0, $totals['rewritten']);
        Http::assertNothingSent();
    }

    public function test_a_refusal_leaves_the_draft_for_a_human_rather_than_publishing_it(): void
    {
        $draft = $this->draft();
        // An empty title is how the prompt asks the model to decline.
        $this->fakeAnthropic(['title' => '', 'body' => '']);

        $totals = app(NewsRewriteService::class)->run(10);

        $draft->refresh();
        $this->assertSame(0, $totals['rewritten']);
        $this->assertSame(1, $totals['failed']);
        $this->assertNull($draft->body, 'a refusal must leave the draft retryable');
        $this->assertFalse($draft->active);
        $this->assertTrue($draft->noindex, 'a draft that still holds the source wording stays unindexable');
    }

    // ── covers ───────────────────────────────────────────────────────────────

    /**
     * The feeds carry photographs and they are deliberately not used: a news
     * photograph is a separately owned work, unlike the facts this pipeline is
     * built on. Nothing a cover contains may come from the source.
     */
    public function test_a_cover_is_drawn_rather_than_taken_from_the_source(): void
    {
        $this->fakeFeed();

        app(NewsScrapeService::class)->run();

        foreach (Article::all() as $article) {
            $svg = Storage::disk('public')->get($article->hero_image_path);

            $this->assertStringContainsString('<svg', $svg);

            // No remote reference of any kind — not the source's CDN, not
            // anyone's. A drawn cover has no network dependency at all.
            //
            // The one permitted http occurrence is the SVG namespace
            // declaration, which is an identifier rather than something a
            // renderer fetches, so it is removed before the check.
            $withoutNamespace = str_replace('http://www.w3.org/2000/svg', '', $svg);
            $this->assertStringNotContainsString('http', $withoutNamespace);
            $this->assertStringNotContainsString('<image', $svg);
            $this->assertStringNotContainsString('href', $svg);
            $this->assertStringNotContainsString('src=', $svg);
        }
    }

    public function test_the_mark_follows_the_story_and_the_colour_follows_the_slug(): void
    {
        $covers = app(\App\Services\News\NewsCoverGenerator::class);

        $licensing = $this->svgFor($covers, 'a', 'Regulator renews eight operator licences');
        $payments  = $this->svgFor($covers, 'b', 'Operator adds new withdrawal and deposit methods');
        $care      = $this->svgFor($covers, 'c', 'Self-exclusion scheme expands across brands');
        $general   = $this->svgFor($covers, 'd', 'New slot launches this week');

        // Four topics, four distinct marks — the feed is legible at a glance.
        $this->assertCount(4, array_unique([$licensing, $payments, $care, $general]));

        // Same slug, same cover: a re-run overwrites one file rather than
        // accumulating orphans.
        $this->assertSame($licensing, $this->svgFor($covers, 'a', 'Regulator renews eight operator licences'));

        // Different slugs on the same topic still differ, so a page of
        // licensing stories is not twenty identical cards.
        $this->assertNotSame(
            $this->svgFor($covers, 'e', 'Regulator fines an operator'),
            $this->svgFor($covers, 'f', 'Regulator fines another operator'),
        );
    }

    private function svgFor(\App\Services\News\NewsCoverGenerator $covers, string $slug, string $headline): string
    {
        return Storage::disk('public')->get($covers->generate($slug, $headline));
    }

    public function test_the_rewriter_refuses_to_run_without_a_key(): void
    {
        $this->draft();
        config(['services.anthropic.key' => '']);

        $totals = app(NewsRewriteService::class)->run(10);

        $this->assertSame(0, $totals['rewritten']);
        Http::assertNothingSent();
    }
}
