<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Article;
use App\Models\Site;
use App\Services\News\NewsRewriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * SEO audit C1: feed-made news is indexed only after an editor adds value.
 *
 * - the admin refuses to take a news post out of noindex until it has 300+
 *   words and a link into this site (NewsIndexGate);
 * - `news:rewrite` produces at most `news.rewrite.daily_per_site` a day;
 * - `news:noindex-unreviewed` takes a site's live feed-made posts out of search.
 */
class NewsIndexingTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->site, $this->key] = $this->siteWithKey(['news_enabled' => true, 'domain' => 'example-news.com']);
        $this->actingAsAdmin();
    }

    private function newsPost(array $overrides = []): Article
    {
        return Article::create([
            'site_id'      => $this->site->id,
            'type'         => Article::TYPE_NEWS,
            'title'        => 'Regulator renews licences',
            'excerpt'      => 'Excerpt.',
            'body'         => '<p>Short body.</p>',
            'published_at' => now()->subDay(),
            'noindex'      => true,
            ...$overrides,
        ]);
    }

    private function longBody(string $link = ''): string
    {
        return '<p>' . implode(' ', array_fill(0, 300, 'word')) . '</p>' . $link;
    }

    private function unhide(Article $article, ?string $body = null): TestResponse
    {
        return $this->putJson("/api/v1/admin/sites/{$this->site->id}/articles/{$article->id}?type=news", [
            'title' => $article->title, 'body' => $body ?? $article->body, 'noindex' => false,
        ]);
    }

    // ── the gate ─────────────────────────────────────────────────────────────

    public function test_a_short_or_unlinked_post_stays_out_of_search(): void
    {
        $post = $this->newsPost();

        $this->unhide($post)->assertUnprocessable()->assertJsonValidationErrors('noindex');
        $this->unhide($post, $this->longBody())->assertUnprocessable()->assertJsonValidationErrors('noindex');
        $this->unhide($post, $this->longBody('<a href="https://other-site.com/casinos/bitstarz">x</a>'))
            ->assertUnprocessable();
        $this->unhide($post, $this->longBody('<a href="/about">x</a>'))->assertUnprocessable();

        $this->assertTrue($post->fresh()->noindex);
    }

    public function test_a_long_post_linking_into_the_site_can_be_indexed(): void
    {
        $relative = $this->newsPost();
        $this->unhide($relative, $this->longBody('<a href="/casinos/bitstarz">BitStarz review</a>'))->assertOk();
        $this->assertFalse($relative->fresh()->noindex);

        $absolute = $this->newsPost(['title' => 'Second post']);
        $this->unhide($absolute, $this->longBody('<a href="https://www.example-news.com/bonuses/free-spins">spins</a>'))->assertOk();
        $this->assertFalse($absolute->fresh()->noindex);
    }

    public function test_an_already_indexable_post_keeps_its_edits_and_toggles(): void
    {
        $post = $this->newsPost(['noindex' => false]);

        $this->putJson("/api/v1/admin/sites/{$this->site->id}/articles/{$post->id}?type=news", [
            'title' => $post->title, 'body' => $post->body, 'noindex' => false, 'featured' => true,
        ])->assertOk();
    }

    public function test_a_news_post_is_created_indexable_only_when_it_clears_the_bar(): void
    {
        $base = "/api/v1/admin/sites/{$this->site->id}/articles?type=news";

        $this->postJson($base, ['title' => 'Thin', 'body' => 'Body'])
            ->assertUnprocessable()->assertJsonValidationErrors('noindex');
        $this->postJson($base, ['title' => 'Thin but hidden', 'body' => 'Body', 'noindex' => true])->assertCreated();
        $this->postJson($base, ['title' => 'Full', 'body' => $this->longBody('<a href="/categories/crypto">crypto</a>')])
            ->assertCreated();

        // Guides are not news: no gate.
        $this->postJson("/api/v1/admin/sites/{$this->site->id}/articles?type=guide", ['title' => 'Guide', 'body' => 'Body'])
            ->assertCreated();
    }

    // ── the daily cap ────────────────────────────────────────────────────────

    public function test_the_rewriter_takes_at_most_the_daily_cap_per_site(): void
    {
        config(['news.rewrite.daily_per_site' => 2]);
        [$other] = $this->siteWithKey(['news_enabled' => true]);

        // One already rewritten today on this site: one slot left.
        $this->newsPost(['source_ref' => 'feed:done', 'body' => '<p>Written.</p>']);

        foreach (range(1, 3) as $i) {
            $this->newsPost(['source_ref' => "feed:a{$i}", 'body' => null, 'title' => "Draft {$i}"]);
            $this->newsPost(['source_ref' => "feed:b{$i}", 'body' => null, 'title' => "Other {$i}", 'site_id' => $other->id]);
        }

        $pending = app(NewsRewriteService::class)->pending(10);

        $this->assertSame(1, $pending->where('site_id', $this->site->id)->count());
        $this->assertSame(2, $pending->where('site_id', $other->id)->count());

        // Naming one article bypasses the cap.
        $id = Article::query()->where('source_ref', 'feed:a3')->value('id');
        $this->assertCount(1, app(NewsRewriteService::class)->pending(10, $id));
    }

    public function test_yesterdays_rewrites_do_not_count_against_today(): void
    {
        config(['news.rewrite.daily_per_site' => 1]);
        Carbon::setTestNow(now()->subDay());
        $this->newsPost(['source_ref' => 'feed:old', 'body' => '<p>Written yesterday.</p>']);
        Carbon::setTestNow();

        $this->newsPost(['source_ref' => 'feed:new', 'body' => null, 'title' => 'Fresh draft']);

        $this->assertCount(1, app(NewsRewriteService::class)->pending(10));
    }

    // ── news:noindex-unreviewed ──────────────────────────────────────────────

    public function test_the_command_hides_live_feed_posts_from_search_only(): void
    {
        Bus::fake([RevalidateNextJsSites::class]);
        [$other] = $this->siteWithKey(['news_enabled' => true]);

        $scraped = $this->newsPost(['source_ref' => 'feed:1', 'noindex' => false, 'active' => true]);
        $handWritten = $this->newsPost(['title' => 'Hand written', 'noindex' => false]);
        $elsewhere = $this->newsPost(['source_ref' => 'feed:2', 'noindex' => false, 'site_id' => $other->id, 'slug' => Str::random(8)]);
        $contentDate = $scraped->fresh()->content_updated_at?->toISOString();

        $this->artisan('news:noindex-unreviewed', ['site' => $this->site->slug, '--dry-run' => true])->assertSuccessful();
        $this->assertFalse($scraped->fresh()->noindex, 'a dry run writes nothing');

        $this->artisan('news:noindex-unreviewed', ['site' => $this->site->slug])->assertSuccessful();

        $fresh = $scraped->fresh();
        $this->assertTrue($fresh->noindex);
        $this->assertTrue($fresh->active, 'still published');
        $this->assertSame($contentDate, $fresh->content_updated_at?->toISOString(), 'not a content change');
        $this->assertFalse($handWritten->fresh()->noindex);
        $this->assertFalse($elsewhere->fresh()->noindex);
        Bus::assertDispatched(RevalidateNextJsSites::class, fn (RevalidateNextJsSites $job) => $job->siteIds === [$this->site->id]
            && in_array('news:' . $scraped->slug, $job->tags, true));

        $this->artisan('news:noindex-unreviewed', ['site' => $this->site->slug])
            ->expectsOutputToContain('Nothing to do')->assertSuccessful();
    }

    public function test_the_command_rejects_an_unknown_site(): void
    {
        $this->artisan('news:noindex-unreviewed', ['site' => 'no-such-site'])->assertFailed();
    }

    // ── the editor's note ────────────────────────────────────────────────────

    public function test_the_editors_note_is_saved_counted_toward_the_bar_and_served(): void
    {
        $post = $this->newsPost(['active' => true]);
        $link = '<a href="/casinos/bitstarz">BitStarz review</a>';
        $body = '<p>' . implode(' ', array_fill(0, 200, 'word')) . '</p>' . $link;
        $url = "/api/v1/admin/sites/{$this->site->id}/articles/{$post->id}?type=news";

        // 201 words alone are under the bar…
        $this->putJson($url, ['title' => $post->title, 'body' => $body, 'noindex' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('noindex');

        // …and the note's own words carry it over.
        $note = implode(' ', array_fill(0, 120, 'note'));
        $this->putJson($url, ['title' => $post->title, 'body' => $body, 'editor_note' => $note, 'noindex' => false])
            ->assertOk();

        $post->refresh();
        $this->assertSame($note, $post->editor_note);
        $this->assertFalse($post->noindex);

        $this->getJson("/api/v1/public/sites/{$this->site->slug}/news/{$post->slug}", ['X-Site-Key' => $this->key])
            ->assertOk()
            ->assertJsonPath('data.editor_note', $note);

        // Listings do not carry it, like the body.
        $this->getJson("/api/v1/public/sites/{$this->site->slug}/news", ['X-Site-Key' => $this->key])
            ->assertOk()
            ->assertJsonMissingPath('data.posts.0.editor_note');
    }

    public function test_an_overlong_editors_note_is_rejected(): void
    {
        $post = $this->newsPost();

        $this->putJson("/api/v1/admin/sites/{$this->site->id}/articles/{$post->id}?type=news", [
            'title' => $post->title, 'editor_note' => str_repeat('x', 2001),
        ])->assertUnprocessable()->assertJsonValidationErrors('editor_note');
    }
}
