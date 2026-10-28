<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Article;
use App\Models\Site;
use App\Services\News\NewsRewriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * `news:rewrite`: scraped facts in, original copy out — and the checks that
 * stop a rewrite which is really the source's wording from being saved.
 *
 * The model is never called. Every reply is an Http::fake() in the shape of
 * Gemini's Interactions API, and Sleep is faked so the retry backoff and the
 * delay between items cost no wall-clock time.
 */
class NewsRewriteTest extends TestCase
{
    use RefreshDatabase;

    private const string HEADLINE = 'Regulator renews eight operator licences';

    private const string TEASER = 'The regulator has renewed the licences of eight operators following its periodic review.';

    private const string NEW_TITLE = 'What the latest licence review means for players';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Bus::fake([RevalidateNextJsSites::class]);
        Sleep::fake();

        $this->site = Site::factory()->create(['slug' => 'winpalack', 'news_enabled' => true]);

        config([
            'news.rewrite.api_key' => 'test-key',
            'news.rewrite.model'   => 'gemini-test',
        ]);
    }

    private function draft(array $overrides = []): Article
    {
        $slug = $overrides['slug'] ?? 'regulator-renews-eight-operator-licences';
        Storage::disk('public')->put("news/{$slug}.svg", '<svg/>');

        return Article::create([
            'site_id' => $this->site->id,
            'type'    => Article::TYPE_NEWS,
            'title'   => self::HEADLINE,
            'slug'    => $slug,
            'excerpt' => self::TEASER,
            'body'    => null,
            'active'  => false,
            'noindex' => true,
            'source_ref'  => 'igamingbusiness:1',
            'source_name' => 'iGaming Business',
            'source_url'  => 'https://igamingbusiness.com/example/',
            'hero_image_path' => "news/{$slug}.svg",
            'published_at' => now()->subDay(),
            ...$overrides,
        ]);
    }

    /** Over 100 words, none of them a six-word run from the source. */
    private function originalBody(): string
    {
        $sentence = 'Players who hold accounts with these brands can keep using them while the licence terms stay in force.';

        return '<p>' . implode(' ', array_fill(0, 6, $sentence)) . '</p>'
            . '<h2>What this means for players</h2><p>Nothing changes for existing customers.</p>';
    }

    /** @param array<string, string> $fields */
    private function fakeGemini(array $fields = [], int $status = 200): void
    {
        $this->fakeGeminiText(json_encode([
            'title'     => self::NEW_TITLE,
            'excerpt'   => 'A periodic review ended with eight renewals. Here is what that changes.',
            'body_html' => $this->originalBody(),
            ...$fields,
        ]), $status);
    }

    private function fakeGeminiText(string $text, int $status = 200): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                ['steps' => [
                    ['type' => 'thought', 'signature' => 'x'],
                    ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => $text]]],
                ]],
                $status,
            ),
        ]);
    }

    private function rewrite(Article $article, bool $dryRun = false): array
    {
        return app(NewsRewriteService::class)->rewrite($article, $dryRun);
    }

    /** The row exactly as the collector left it. */
    private function assertUntouched(Article $article): void
    {
        $article->refresh();
        $this->assertNull($article->body, 'a draft that was not rewritten must stay retryable');
        $this->assertSame(self::HEADLINE, $article->title);
        $this->assertSame(self::TEASER, $article->excerpt);
        $this->assertSame('regulator-renews-eight-operator-licences', $article->slug);
        $this->assertTrue($article->noindex);
        $this->assertFalse($article->active);
        Bus::assertNotDispatched(RevalidateNextJsSites::class);
    }

    // ── the happy path ───────────────────────────────────────────────────────

    public function test_a_draft_gets_original_copy_and_a_new_url_but_stays_hidden(): void
    {
        $draft = $this->draft();
        $this->fakeGemini();

        $result = $this->rewrite($draft);

        $draft->refresh();
        $this->assertSame('rewritten', $result['status']);
        $this->assertSame(self::NEW_TITLE, $draft->title);
        $this->assertStringStartsWith('A periodic review', (string) $draft->excerpt);
        $this->assertStringContainsString('What this means for players', (string) $draft->body);
        $this->assertSame('what-the-latest-licence-review-means-for-players', $draft->slug);
        $this->assertSame($draft->slug, $result['slug']);
        $this->assertFalse($draft->noindex);

        // Approval stays a human act — the one thing a rewrite must not touch.
        $this->assertFalse($draft->active);
        $this->assertSame('igamingbusiness:1', $draft->source_ref, 'the dedup key is never rewritten');

        // The cover follows the slug; the provisional one is gone.
        $this->assertSame('news/what-the-latest-licence-review-means-for-players.svg', $draft->hero_image_path);
        Storage::disk('public')->assertExists($draft->hero_image_path);
        Storage::disk('public')->assertMissing('news/regulator-renews-eight-operator-licences.svg');

        Bus::assertDispatched(RevalidateNextJsSites::class, fn (RevalidateNextJsSites $job) => $job->siteIds === [$this->site->id]
            && $job->tags === ['news', 'news:regulator-renews-eight-operator-licences', 'news:what-the-latest-licence-review-means-for-players']);
    }

    public function test_an_article_already_published_keeps_its_url(): void
    {
        $draft = $this->draft(['active' => true]);
        $this->fakeGemini();

        $this->rewrite($draft);

        $draft->refresh();
        $this->assertSame(self::NEW_TITLE, $draft->title);
        $this->assertSame('regulator-renews-eight-operator-licences', $draft->slug, 'a live URL must not 404');
        $this->assertSame('news/regulator-renews-eight-operator-licences.svg', $draft->hero_image_path);
        Storage::disk('public')->assertExists($draft->hero_image_path);
        $this->assertTrue($draft->active);

        // Deduped: the old and new slug are the same tag.
        Bus::assertDispatched(RevalidateNextJsSites::class, fn (RevalidateNextJsSites $job) => $job->tags === ['news', 'news:regulator-renews-eight-operator-licences']);
    }

    public function test_a_slug_collision_gets_a_numeric_suffix(): void
    {
        // A soft-deleted row still holds the unique index, so it must count.
        $this->draft([
            'slug'       => 'what-the-latest-licence-review-means-for-players',
            'source_ref' => 'other:1',
            'body'       => '<p>Taken.</p>',
        ])->delete();
        $draft = $this->draft();
        $this->fakeGemini();

        $this->rewrite($draft);

        $this->assertSame('what-the-latest-licence-review-means-for-players-2', $draft->refresh()->slug);
    }

    public function test_the_body_is_sanitised_to_the_allowed_tags(): void
    {
        $draft = $this->draft();
        $this->fakeGemini([
            'title'     => '<b>' . self::NEW_TITLE . '</b>',
            'body_html' => '<script>alert(1)</script><p><a href="https://evil.test">link</a><img src="x.png"></p>' . $this->originalBody(),
        ]);

        $this->rewrite($draft);

        $body = (string) $draft->refresh()->body;
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('<a', $body);
        $this->assertStringNotContainsString('<img', $body);
        $this->assertStringContainsString('<h2>What this means for players</h2>', $body);
        $this->assertSame(self::NEW_TITLE, $draft->title, 'tags are stripped from the title');
    }

    public function test_the_key_travels_in_a_header_and_never_in_the_url(): void
    {
        $this->fakeGemini();

        $this->rewrite($this->draft());

        Http::assertSent(fn (Request $request) => $request->hasHeader('x-goog-api-key', 'test-key')
            && ! str_contains($request->url(), 'test-key')
            && ! str_contains($request->url(), 'key=')
            && str_ends_with($request->url(), '/v1beta/interactions')
            && $request['model'] === 'gemini-test'
            && $request['response_mime_type'] === 'application/json'
            && $request['response_format']['required'] === ['title', 'excerpt', 'body_html']);
    }

    // ── what is refused ──────────────────────────────────────────────────────

    public function test_a_title_that_is_the_source_headline_is_rejected(): void
    {
        $draft = $this->draft();
        $this->fakeGemini(['title' => 'Regulator renews eight operator licences again']);

        $result = $this->rewrite($draft);

        $this->assertSame('rejected', $result['status']);
        $this->assertStringContainsString('similar to the source headline', (string) $result['reason']);
        $this->assertUntouched($draft);
    }

    public function test_reusing_the_sources_phrasing_is_rejected(): void
    {
        $draft = $this->draft();
        // The teaser, verbatim, inside an otherwise original body.
        $this->fakeGemini(['body_html' => '<p>' . self::TEASER . '</p>' . $this->originalBody()]);

        $result = $this->rewrite($draft);

        $this->assertSame('rejected', $result['status']);
        $this->assertStringContainsString('6-word runs reused verbatim', (string) $result['reason']);
        $this->assertUntouched($draft);
    }

    public function test_a_body_under_a_hundred_words_is_rejected(): void
    {
        $draft = $this->draft();
        $this->fakeGemini(['body_html' => '<p>Eight licences were renewed after a review.</p>']);

        $result = $this->rewrite($draft);

        $this->assertSame('rejected', $result['status']);
        $this->assertStringContainsString('under 100', (string) $result['reason']);
        $this->assertUntouched($draft);
    }

    public function test_a_reply_that_is_not_json_changes_nothing(): void
    {
        $draft = $this->draft();
        $this->fakeGeminiText('Sure! Here is your article: ...');

        $result = $this->rewrite($draft);

        $this->assertSame('failed', $result['status']);
        $this->assertUntouched($draft);
    }

    public function test_an_api_error_changes_nothing_and_does_not_throw(): void
    {
        $draft = $this->draft();
        $this->fakeGemini([], 500);

        $result = $this->rewrite($draft);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('HTTP 500', (string) $result['reason']);
        $this->assertUntouched($draft);
        // Retried before giving up — 429/5xx are routine on the free tier.
        Http::assertSentCount(3);
    }

    public function test_an_api_error_reports_googles_reason(): void
    {
        $draft = $this->draft();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                ['error' => ['code' => 404, 'message' => 'This model is no longer available to new users.']],
                404,
            ),
        ]);

        $result = $this->rewrite($draft);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('model API returned HTTP 404: This model is no longer available to new users.', $result['reason']);
        $this->assertUntouched($draft);
    }

    public function test_a_dry_run_calls_the_model_but_writes_nothing(): void
    {
        $draft = $this->draft();
        $this->fakeGemini();

        $result = $this->rewrite($draft, dryRun: true);

        $this->assertSame('rewritten', $result['status']);
        $this->assertSame('what-the-latest-licence-review-means-for-players', $result['slug']);
        Http::assertSentCount(1);
        $this->assertUntouched($draft);
        Storage::disk('public')->assertMissing('news/what-the-latest-licence-review-means-for-players.svg');
    }

    // ── what is selected ─────────────────────────────────────────────────────

    public function test_only_scraped_drafts_without_a_body_are_pending(): void
    {
        $older = $this->draft(['published_at' => now()->subDays(3), 'source_ref' => 'a:1', 'slug' => 'older']);
        $this->draft(['published_at' => now()->subDays(5), 'source_ref' => 'a:2', 'slug' => 'done', 'body' => '<p>Written.</p>']);
        $this->draft(['published_at' => now()->subDays(9), 'source_ref' => null, 'slug' => 'hand-written']);
        $newer = $this->draft(['published_at' => now()->subDay(), 'source_ref' => 'a:3', 'slug' => 'newer']);

        $pending = app(NewsRewriteService::class)->pending(10);

        // Oldest story first: the one that has waited longest goes stale first.
        $this->assertSame([$older->id, $newer->id], $pending->modelKeys());
    }

    // ── the command ──────────────────────────────────────────────────────────

    public function test_the_command_refuses_to_run_without_a_key(): void
    {
        $this->draft();
        config(['news.rewrite.api_key' => '']);
        Http::fake();

        $this->artisan('news:rewrite')
            ->expectsOutputToContain('GEMINI_API_KEY is not set')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_the_command_can_target_one_article(): void
    {
        $first = $this->draft(['source_ref' => 'a:1', 'slug' => 'first']);
        $second = $this->draft(['source_ref' => 'a:2', 'slug' => 'second']);
        $this->fakeGemini();

        $this->artisan('news:rewrite', ['--id' => $second->id])
            ->expectsOutputToContain("✓ #{$second->id} → what-the-latest-licence-review-means-for-players")
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertNull($first->refresh()->body);
        $this->assertNotNull($second->refresh()->body);
    }

    public function test_the_command_fails_only_when_nothing_was_rewritten_and_something_broke(): void
    {
        $this->draft();
        $this->fakeGemini([], 500);

        $this->artisan('news:rewrite')
            ->expectsOutputToContain('failed: model API returned HTTP 500')
            ->assertFailed();
    }

    public function test_rejections_alone_are_not_a_failed_run(): void
    {
        $draft = $this->draft();
        $this->fakeGemini(['body_html' => '<p>Too short.</p>']);

        $this->artisan('news:rewrite')
            ->expectsOutputToContain("✗ #{$draft->id} rejected:")
            ->assertSuccessful();
    }

    public function test_one_success_makes_the_run_successful_despite_a_failure(): void
    {
        $this->draft(['source_ref' => 'a:1', 'slug' => 'first', 'published_at' => now()->subDays(2)]);
        $this->draft(['source_ref' => 'a:2', 'slug' => 'second']);

        $ok = ['steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode([
            'title'     => self::NEW_TITLE,
            'excerpt'   => 'A periodic review ended with eight renewals.',
            'body_html' => $this->originalBody(),
        ])]]]]];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($ok)
                ->push([], 500)->push([], 500)->push([], 500),
        ]);

        $this->artisan('news:rewrite')
            ->expectsOutputToContain('1 rewritten, 0 rejected, 1 failed.')
            ->assertSuccessful();

        // The pause sits between items, never before the first.
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 5, 1);
    }
}
