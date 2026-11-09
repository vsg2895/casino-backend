<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Article;
use App\Models\Author;
use App\Models\DeletedNewsSlug;
use App\Models\Site;
use App\Services\News\NewsRemovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Deleting news without SEO damage: the old URL answers 410 (via `news/gone`),
 * links to it from other articles are unwrapped without moving their content
 * date or indexability, and the bulk command deletes only what is unused.
 */
class NewsRemovalTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([RevalidateNextJsSites::class]);
        Carbon::setTestNow('2026-11-08 12:00:00');
        [$this->site, $this->key] = $this->siteWithKey(['news_enabled' => true, 'domain' => 'winpalack.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function newsPost(string $slug, int $daysAgo, array $attrs = []): Article
    {
        return Article::create([
            'site_id'      => $this->site->id,
            'type'         => Article::TYPE_NEWS,
            'title'        => ucfirst(str_replace('-', ' ', $slug)),
            'slug'         => $slug,
            'body'         => '<p>Body.</p>',
            'published_at' => now()->subDays($daysAgo),
            'active'       => true,
            ...$attrs,
        ]);
    }

    /** @return list<string> */
    private function goneList(): array
    {
        return $this->getJson($this->publicBase($this->site) . '/news/gone', $this->siteHeaders($this->key))
            ->assertOk()
            ->json('data');
    }

    public function test_the_admin_delete_unwraps_links_to_the_post_and_lists_it_as_gone(): void
    {
        $this->actingAsAdmin();
        $old = $this->newsPost('old-story', 100);
        $other = $this->newsPost('other-story', 1, [
            'noindex' => false,
            'body'    => '<p>See <a href="/news/old-story">the old story</a>, '
                . '<a href="https://www.winpalack.com/news/Old-Story/?x=1">again</a>, '
                . '<a href="/news/kept-story">a kept one</a> and <a href="/casinos/bitstarz">BitStarz</a>.</p>',
        ]);
        $this->newsPost('kept-story', 2);
        $contentDate = $other->fresh()->content_updated_at;
        Carbon::setTestNow(now()->addHour());

        $this->deleteJson("/api/v1/admin/sites/{$this->site->id}/articles/{$old->id}?type=news")->assertNoContent();

        // Gone for good: no row, trashed or not — only the tombstone.
        $this->assertNull(Article::withTrashed()->find($old->id));
        $this->assertDatabaseHas('deleted_news_slugs', ['site_id' => $this->site->id, 'slug' => 'old-story', 'was_public' => true]);
        $other->refresh();
        $this->assertSame(
            '<p>See the old story, again, <a href="/news/kept-story">a kept one</a> and <a href="/casinos/bitstarz">BitStarz</a>.</p>',
            $other->body,
        );
        // Not a content change, and the post stays indexable.
        $this->assertEquals($contentDate, $other->content_updated_at);
        $this->assertFalse($other->noindex);
        $this->assertSame(['old-story'], $this->goneList());
        Bus::assertDispatched(RevalidateNextJsSites::class, fn (RevalidateNextJsSites $job): bool => $job->siteIds === [$this->site->id]);
    }

    public function test_the_gone_list_holds_only_posts_that_were_public_and_recently_deleted(): void
    {
        $removal = app(NewsRemovalService::class);
        $removal->remove($this->site, [$this->newsPost('was-live', 50)]);
        $removal->remove($this->site, [$this->newsPost('never-written', 50, ['body' => null])]);
        $removal->remove($this->site, [$this->newsPost('never-published', 50, ['published_at' => null, 'active' => false])]);
        $removal->remove($this->site, [$this->newsPost('long-ago', 900)]);
        DeletedNewsSlug::query()->where('slug', 'long-ago')->update(['deleted_at' => now()->subDays(400)]);
        // Soft-deleted before deletes were permanent: still answered 410.
        $this->newsPost('old-soft', 50)->delete();
        $this->newsPost('still-live', 1);

        $this->assertSame(['old-soft', 'was-live'], $this->goneList());
    }

    public function test_a_deleted_slug_is_never_reused_or_re_scraped(): void
    {
        $this->actingAsAdmin();
        app(NewsRemovalService::class)->remove($this->site, [
            $this->newsPost('big-story', 50, ['source_ref' => 'feed:1']),
        ]);

        // Generated from the same title: steps past the tombstone.
        $again = Article::create([
            'site_id' => $this->site->id, 'type' => Article::TYPE_NEWS, 'title' => 'Big story',
        ]);
        $this->assertSame('big-story-2', $again->slug);

        // Typed by an editor: refused.
        $this->postJson("/api/v1/admin/sites/{$this->site->id}/articles?type=news", ['title' => 'X', 'slug' => 'big-story'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');

        $this->assertTrue(DeletedNewsSlug::holds($this->site->id, sourceRef: 'feed:1'));
        $this->assertSame(['big-story'], $this->goneList());
    }

    public function test_purge_trashed_hard_deletes_old_soft_deleted_news_and_keeps_the_410(): void
    {
        $soft = $this->newsPost('soft-one', 100);
        $soft->delete();

        $this->artisan('news:delete-old', ['site' => $this->site->slug, '--purge-trashed' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, Article::withTrashed()->count());
        $this->assertSame(['soft-one'], $this->goneList());
    }

    public function test_the_command_deletes_only_old_unused_posts(): void
    {
        $author = Author::create(['site_id' => $this->site->id, 'name' => 'Ann', 'slug' => 'ann']);
        $this->newsPost('old-unused', 100);
        $this->newsPost('old-hidden', 100, ['active' => false]);
        $this->newsPost('old-featured', 100, ['featured' => true]);
        $this->newsPost('old-popular', 100, ['to_be_most_popular' => true]);
        $this->newsPost('old-noted', 100, ['editor_note' => 'Our view.']);
        $this->newsPost('old-reviewed', 100, ['reviewed_by_id' => $author->id]);
        $this->newsPost('recent', 10);
        Article::create([
            'site_id' => $this->site->id, 'type' => Article::TYPE_GUIDE, 'title' => 'Old guide',
            'slug' => 'old-guide', 'body' => '<p>x</p>', 'published_at' => now()->subDays(400),
        ]);

        $this->artisan('news:delete-old', ['site' => $this->site->slug, '--older-than' => 90, '--dry-run' => true])
            ->assertSuccessful();
        $this->assertSame(7, Article::query()->ofType(Article::TYPE_NEWS)->count());

        $this->artisan('news:delete-old', ['site' => $this->site->slug, '--older-than' => 90, '--force' => true])
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(['old-unused', 'old-hidden'], DeletedNewsSlug::query()->pluck('slug')->all());
        $this->assertSame(0, Article::onlyTrashed()->count());

        $this->artisan('news:delete-old', [
            'site' => $this->site->slug, '--older-than' => 90, '--include-reviewed' => true, '--force' => true,
        ])->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['old-featured', 'old-popular', 'recent', 'old-guide'],
            Article::query()->pluck('slug')->all(),
        );
    }

    public function test_drafts_only_leaves_live_posts_alone(): void
    {
        $this->newsPost('old-live', 100);
        $this->newsPost('old-hidden', 100, ['active' => false]);
        $this->newsPost('old-unwritten', 100, ['body' => null]);

        $this->artisan('news:delete-old', [
            'site' => $this->site->slug, '--older-than' => 30, '--drafts-only' => true, '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(['old-live'], Article::query()->pluck('slug')->all());
    }

    public function test_the_command_requires_an_age(): void
    {
        $this->newsPost('old-unused', 100);

        $this->artisan('news:delete-old', ['site' => $this->site->slug, '--force' => true])->assertFailed();

        $this->assertSame(1, Article::query()->count());
    }
}
