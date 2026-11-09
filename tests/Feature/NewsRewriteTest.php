<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Article;
use App\Models\Author;
use App\Models\Casino;
use App\Models\Category;
use App\Models\Site;
use App\Services\News\NewsRewriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            'meta_title'       => 'Licence review ends with eight renewals',
            'meta_description' => 'A periodic licence review renewed eight operators. What it covers, and what it changes for players at those sites.',
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
        $this->assertSame('rewritten', $result['status'], (string) $result['reason']);
        $this->assertSame(self::NEW_TITLE, $draft->title);
        $this->assertStringStartsWith('A periodic review', (string) $draft->excerpt);
        $this->assertStringContainsString('What this means for players', (string) $draft->body);
        $this->assertSame('what-the-latest-licence-review-means-for-players', $draft->slug);
        $this->assertSame($draft->slug, $result['slug']);
        // Original wording is not added value: indexing stays an editor's call
        // (NewsIndexGate), so the rewrite leaves noindex on.
        $this->assertTrue($draft->noindex);

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

    public function test_the_rewrite_writes_the_meta_title_and_description(): void
    {
        $draft = $this->draft(['meta_title' => 'Old headline meta', 'meta_description' => 'Old snippet']);
        $this->fakeGemini();

        $this->rewrite($draft);

        $draft->refresh();
        $this->assertSame('Licence review ends with eight renewals', $draft->meta_title);
        $this->assertStringStartsWith('A periodic licence review renewed eight operators.', (string) $draft->meta_description);
    }

    public function test_an_overlong_meta_is_cut_at_a_whole_word(): void
    {
        $draft = $this->draft();
        $this->fakeGemini([
            'meta_title'       => 'Regulator completes its review and renews eight operator licences, again',
            'meta_description' => str_repeat('Players should check the licence page. ', 6),
        ]);

        $this->rewrite($draft);

        $draft->refresh();
        $this->assertSame('Regulator completes its review and renews eight operator', $draft->meta_title);
        $this->assertLessThanOrEqual(155, mb_strlen((string) $draft->meta_description));
        $this->assertStringEndsWith('licence page', (string) $draft->meta_description);
    }

    public function test_a_missing_meta_is_left_empty_and_the_rewrite_still_saves(): void
    {
        $draft = $this->draft(['meta_title' => 'Old headline meta']);
        $this->fakeGemini(['meta_title' => '', 'meta_description' => '   ']);

        $result = $this->rewrite($draft);

        $draft->refresh();
        $this->assertSame('rewritten', $result['status'], (string) $result['reason']);
        // Null, so the page falls back to the new title and excerpt rather
        // than keeping a meta written for the old headline.
        $this->assertNull($draft->meta_title);
        $this->assertNull($draft->meta_description);
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
            && $request['response_format']['required'] === ['title', 'excerpt', 'body_html', 'meta_title', 'meta_description']);
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
        // The fake draft is under the index bar, so the two repair requests run
        // too — a dry run shows what a real run would do.
        Http::assertSentCount(3);
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

        // Newest story first: the oldest day is the next one news:scrape deletes.
        $this->assertSame([$newer->id, $older->id], $pending->modelKeys());
    }

    public function test_a_limited_run_takes_the_newest_drafts(): void
    {
        $this->draft(['published_at' => now()->subDays(20), 'source_ref' => 'a:1', 'slug' => 'stale']);
        $today = $this->draft(['published_at' => now()->subHour(), 'source_ref' => 'a:2', 'slug' => 'today']);

        $this->assertSame([$today->id], app(NewsRewriteService::class)->pending(1)->modelKeys());
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

        // One article: its request plus the two repairs of a short draft.
        Http::assertSentCount(3);
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
            ->expectsOutputToContain('1 rewritten (0 indexable), 0 rejected, 1 failed.')
            ->assertSuccessful();

        // The pause sits between items, never before the first.
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 5, 1);
    }

    // ── internal links and indexing ──────────────────────────────────────────

    /** A category and a casino this site really lists, so both pages exist. */
    private function linkablePages(): void
    {
        $casino = Casino::factory()->create(['name' => 'Northstar', 'slug' => 'northstar', 'active' => true]);
        $casino->sites()->attach($this->site->id, ['active' => true, 'position' => 1, 'affiliate_url' => 'https://example.com/go']);
        Category::factory()->create(['name' => 'Fast Payout', 'slug' => 'fast-payout'])->casinos()->attach($casino->id);
    }

    /** Over NewsIndexGate::MIN_WORDS words, none a six-word run from the source. */
    private function longBody(string $links): string
    {
        $sentence = 'Players who hold accounts with these brands can keep using them while the licence terms stay in force.';

        return '<p>' . implode(' ', array_fill(0, 18, $sentence)) . '</p>'
            . '<h2>What this means for players</h2><p>' . $links . ' Nothing changes for existing customers.</p>';
    }

    public function test_the_model_is_offered_only_this_sites_pages(): void
    {
        $this->linkablePages();
        $this->fakeGemini();

        $this->rewrite($this->draft());

        Http::assertSent(fn (Request $request) => str_contains((string) $request['input'], '- Fast Payout casinos: /categories/fast-payout')
            && str_contains((string) $request['input'], '- Northstar review: /casinos/northstar'));
    }

    public function test_a_long_rewrite_with_a_real_internal_link_is_indexable_but_stays_hidden(): void
    {
        $this->linkablePages();
        $draft = $this->draft();
        $this->fakeGemini(['body_html' => $this->longBody(
            'See our <a href="/categories/fast-payout" onclick="x()">fast payout casinos</a> and '
            . '<a href="/casinos/made-up">a page that does not exist</a> or <a href="https://evil.test">elsewhere</a>.',
        )]);

        $result = $this->rewrite($draft);

        $draft->refresh();
        $this->assertTrue($result['indexed']);
        $this->assertNull($result['reason']);
        $this->assertFalse($draft->noindex, 'it clears NewsIndexGate, so it may be indexed');
        $this->assertFalse($draft->active, 'publishing stays an editor\'s act');

        $body = (string) $draft->body;
        $this->assertStringContainsString('<a href="/categories/fast-payout">fast payout casinos</a>', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringNotContainsString('made-up', $body, 'a path that was not offered is unwrapped');
        $this->assertStringNotContainsString('evil.test', $body);
        $this->assertStringContainsString('a page that does not exist', $body, 'the anchor text survives');
    }

    public function test_a_rewrite_under_the_bar_stays_noindex_and_says_why(): void
    {
        $draft = $this->draft();
        $this->fakeGemini();

        $result = $this->rewrite($draft);

        $this->assertSame('rewritten', $result['status']);
        $this->assertFalse($result['indexed']);
        $this->assertStringContainsString('kept noindex', (string) $result['reason']);
        $this->assertTrue($draft->refresh()->noindex);
    }

    /** A Gemini reply carrying this JSON, for a sequence. */
    private function geminiReply(array $fields): array
    {
        return ['steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode([
            'title'            => self::NEW_TITLE,
            'excerpt'          => 'A periodic review ended with eight renewals. Here is what that changes.',
            'body_html'        => $this->originalBody(),
            'meta_title'       => 'Licence review ends with eight renewals',
            'meta_description' => 'A periodic licence review renewed eight operators. What it covers, and what it changes for players at those sites.',
            ...$fields,
        ])]]]]];
    }

    public function test_a_draft_under_the_bar_is_repaired_automatically(): void
    {
        $this->linkablePages();
        $draft = $this->draft();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->geminiReply([]))
            ->push($this->geminiReply(['body_html' => $this->longBody('See our <a href="/categories/fast-payout">fast payout casinos</a>.')]))]);

        $result = $this->rewrite($draft);

        $this->assertTrue($result['indexed']);
        $this->assertNull($result['reason']);
        $this->assertFalse($draft->refresh()->noindex);
        Http::assertSentCount(2);
        // The repair hands back the draft and names exactly what is missing.
        Http::assertSent(fn (Request $request) => str_contains((string) $request['input'], 'Your previous draft did not meet the requirements')
            && str_contains((string) $request['input'], 'it must have 350 to 500')
            && str_contains((string) $request['input'], 'links none of the listed site pages'));
    }

    public function test_a_long_draft_that_only_lacks_a_link_gets_a_related_link(): void
    {
        $this->linkablePages();
        $draft = $this->draft();
        // Every reply, repairs included, is long but links nothing.
        $this->fakeGemini(['body_html' => $this->longBody('No links here.')]);

        $result = $this->rewrite($draft);

        $this->assertTrue($result['indexed']);
        $this->assertMatchesRegularExpression(
            '#<p>Related on this site: <a href="/(categories|casinos)/[a-z0-9-]+">[^<]+</a>\.</p>$#',
            (string) $draft->refresh()->body,
        );
        $this->assertFalse($draft->noindex);
    }

    public function test_a_draft_still_short_after_the_repairs_stays_noindex_with_no_padding(): void
    {
        $this->linkablePages();
        $draft = $this->draft();
        $this->fakeGemini();

        $result = $this->rewrite($draft);

        $this->assertFalse($result['indexed']);
        $this->assertStringContainsString('words (at least 300)', (string) $result['reason']);
        // No link was bolted on to a draft that is short anyway.
        $this->assertStringNotContainsString('Related on this site', (string) $draft->refresh()->body);
        Http::assertSentCount(3);
    }

    public function test_repairs_can_be_switched_off(): void
    {
        config(['news.rewrite.repair_attempts' => 0]);
        $this->fakeGemini();

        $this->rewrite($this->draft());

        Http::assertSentCount(1);
    }

    public function test_the_site_news_desk_becomes_the_author_and_no_reviewer_is_set(): void
    {
        $first = $this->draft(['source_ref' => 'a:1', 'slug' => 'first']);
        $second = $this->draft(['source_ref' => 'a:2', 'slug' => 'second']);
        $this->fakeGemini();

        $this->rewrite($first);
        $this->rewrite($second);

        $desk = Author::query()->where('site_id', $this->site->id)->where('slug', Str::slug($this->site->name . ' team'))->sole();
        $this->assertSame($this->site->name . ' Team', $desk->name);
        $this->assertSame('News desk', $desk->role);
        $this->assertTrue($desk->visible);
        // One desk author per site, reused.
        $this->assertSame($desk->id, $first->refresh()->author_id);
        $this->assertSame($desk->id, $second->refresh()->author_id);
        $this->assertNull($first->reviewed_by_id);
    }

    public function test_an_author_an_editor_chose_is_kept(): void
    {
        $person = Author::factory()->create(['site_id' => $this->site->id, 'name' => 'Ann Editor']);
        $draft = $this->draft(['author_id' => $person->id]);
        $this->fakeGemini();

        $this->rewrite($draft);

        $this->assertSame($person->id, $draft->refresh()->author_id);
        $this->assertSame(1, Author::query()->where('site_id', $this->site->id)->count(), 'no desk author is created for it');
    }

    public function test_all_rewrites_written_posts_again_from_their_own_copy(): void
    {
        $this->linkablePages();
        config(['news.rewrite.daily_per_site' => 1]);
        $live = $this->draft([
            'title'   => self::NEW_TITLE,
            'excerpt' => 'Our earlier standfirst.',
            'body'    => '<p>Our earlier body.</p>',
            'active'  => true,
            'noindex' => true,
            'source_ref' => 'a:1',
        ]);
        $this->draft(['source_ref' => 'a:2', 'slug' => 'unwritten']);

        // The written post is not pending without --all, and it used today's cap.
        $this->assertSame([], app(NewsRewriteService::class)->pending(10)->all());
        // With --all, both are, and the daily cap does not apply.
        $this->assertCount(2, app(NewsRewriteService::class)->pending(10, all: true));

        // The same title as before is fine: it is our own, not the source's.
        $this->fakeGemini(['body_html' => $this->longBody('Compare <a href="/casinos/northstar">Northstar</a>.')]);

        $this->artisan('news:rewrite', ['--all' => true, '--id' => $live->id])
            ->expectsOutputToContain("✓ #{$live->id} → regulator-renews-eight-operator-licences (indexable)")
            ->expectsOutputToContain('1 rewritten (1 indexable), 0 rejected, 0 failed.')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains((string) $request['input'], "Earlier version of the article:\nOur earlier body."));
        $live->refresh();
        $this->assertFalse($live->noindex);
        $this->assertTrue($live->active);
        $this->assertSame('regulator-renews-eight-operator-licences', $live->slug, 'a live URL must not 404');
    }

    public function test_id_alone_does_not_rewrite_a_written_post(): void
    {
        $written = $this->draft(['body' => '<p>Written.</p>']);
        Http::fake();

        $this->artisan('news:rewrite', ['--id' => $written->id])
            ->expectsOutputToContain('Add --all to write it again.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_casinos_a_story_bears_on_are_handed_over_with_their_facts_and_may_be_linked(): void
    {
        $this->site->update(['operator_profile_enabled' => true]);
        // Beyond the 20 casinos the catalog offers, so only the match makes it linkable.
        $casino = Casino::factory()->create(['name' => 'Riverbend', 'slug' => 'riverbend', 'active' => true]);
        $casino->sites()->attach($this->site->id, ['active' => true, 'position' => 99, 'affiliate_url' => 'https://example.com/go']);
        $casino->detail()->create(['licences' => ['MGA'], 'withdrawal_time' => '24 hours']);
        foreach (range(1, 20) as $n) {
            Casino::factory()->create(['slug' => "filler-{$n}", 'active' => true])
                ->sites()->attach($this->site->id, ['active' => true, 'position' => $n, 'affiliate_url' => 'https://example.com/go']);
        }

        $draft = $this->draft(['title' => 'Malta Gaming Authority fines eight operators']);
        $this->fakeGemini(['body_html' => $this->longBody('<a href="/casinos/riverbend">Riverbend</a> holds an MGA licence.')]);

        $this->rewrite($draft);

        Http::assertSent(fn (Request $request) => str_contains(
            (string) $request['input'],
            '- Riverbend (/casinos/riverbend): It holds a licence from Malta Gaming Authority, the regulator in the story. Licences: MGA. Withdrawal time: 24 hours.',
        ));
        $this->assertStringContainsString('<a href="/casinos/riverbend">Riverbend</a>', (string) $draft->refresh()->body);
        $this->assertFalse($draft->noindex);
    }

    public function test_the_editors_note_is_kept_and_counts_toward_the_bar(): void
    {
        $this->linkablePages();
        $note = implode(' ', array_fill(0, 200, 'note'));
        $draft = $this->draft(['editor_note' => $note]);
        // About 130 words of body: under the bar alone, over it with the note.
        $this->fakeGemini(['body_html' => $this->originalBody() . '<p><a href="/categories/fast-payout">Fast payouts</a>.</p>']);

        $result = $this->rewrite($draft);

        $draft->refresh();
        $this->assertTrue($result['indexed']);
        $this->assertFalse($draft->noindex);
        $this->assertSame($note, $draft->editor_note, 'the rewrite never touches it');
    }
}
