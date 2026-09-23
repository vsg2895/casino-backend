<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * News, and its separation from guides.
 *
 * The two share a table, so the assertions that matter most are the ones about
 * LEAKAGE: a news post must never appear in the guides feed, a guide must never
 * appear in the news feed, and neither screen may edit the other's rows. Those
 * are the failures a discriminator column invites.
 */
class NewsSectionTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->site, $this->key] = $this->siteWithKey([
            'news_enabled'   => true,
            'guides_enabled' => true,
        ]);
    }

    private function article(string $type, string $title, ?Carbon $publishedAt = null, int $position = 0): Article
    {
        return Article::create([
            'site_id'      => $this->site->id,
            'type'         => $type,
            'title'        => $title,
            'body'         => 'Body copy.',
            'excerpt'      => 'Excerpt.',
            'position'     => $position,
            'published_at' => $publishedAt ?? Carbon::now()->subDay(),
        ]);
    }

    /**
     * @return list<string> titles in the public news feed
     *
     * The endpoint returns `data.posts` alongside `data.popular` and
     * `data.categories` — one request feeds the whole page, so the feed is a
     * key rather than the whole payload.
     */
    private function feed(?string $categorySlug = null): array
    {
        $url = $this->publicBase($this->site) . '/news'
            . ($categorySlug !== null ? '?category=' . $categorySlug : '');

        return array_column(
            $this->getJson($url, $this->siteHeaders($this->key))->assertOk()->json('data.posts'),
            'title',
        );
    }

    // ── the feed ─────────────────────────────────────────────────────────────

    public function test_the_news_feed_returns_published_news(): void
    {
        $this->article(Article::TYPE_NEWS, 'Regulator fines operator');

        $this->assertSame(['Regulator fines operator'], $this->feed());
    }

    public function test_guides_never_appear_in_the_news_feed(): void
    {
        $this->article(Article::TYPE_NEWS, 'A news post');
        $this->article(Article::TYPE_GUIDE, 'A guide');

        $this->assertSame(['A news post'], $this->feed());
    }

    public function test_news_never_appears_in_the_guides_feed(): void
    {
        $this->article(Article::TYPE_NEWS, 'A news post');
        $this->article(Article::TYPE_GUIDE, 'A guide');

        $titles = array_column(
            $this->getJson($this->publicBase($this->site) . '/articles', $this->siteHeaders($this->key))
                ->assertOk()->json('data'),
            'title',
        );

        $this->assertSame(['A guide'], $titles);
    }

    public function test_a_draft_is_not_published(): void
    {
        Article::create([
            'site_id' => $this->site->id, 'type' => Article::TYPE_NEWS,
            'title' => 'Unfinished', 'body' => 'x', 'published_at' => null,
        ]);

        $this->assertSame([], $this->feed());
    }

    /** published_at doubles as scheduling; a future date must stay unpublished. */
    public function test_a_future_dated_post_is_not_published_yet(): void
    {
        $this->article(Article::TYPE_NEWS, 'Tomorrow', Carbon::now()->addDay());

        $this->assertSame([], $this->feed());
    }

    public function test_position_controls_the_order(): void
    {
        $this->article(Article::TYPE_NEWS, 'Third', Carbon::now()->subDays(1), 30);
        $this->article(Article::TYPE_NEWS, 'First', Carbon::now()->subDays(3), 10);
        $this->article(Article::TYPE_NEWS, 'Second', Carbon::now()->subDays(2), 20);

        $this->assertSame(['First', 'Second', 'Third'], $this->feed());
    }

    public function test_equal_positions_fall_back_to_newest_first(): void
    {
        $this->article(Article::TYPE_NEWS, 'Older', Carbon::now()->subDays(5), 0);
        $this->article(Article::TYPE_NEWS, 'Newer', Carbon::now()->subDay(), 0);

        $this->assertSame(['Newer', 'Older'], $this->feed());
    }

    // ── the per-site flag ────────────────────────────────────────────────────

    public function test_both_routes_404_when_news_is_switched_off(): void
    {
        $this->site->update(['news_enabled' => false]);
        $post = $this->article(Article::TYPE_NEWS, 'Hidden');

        $this->getJson($this->publicBase($this->site) . '/news', $this->siteHeaders($this->key))
            ->assertNotFound();
        $this->getJson($this->publicBase($this->site) . '/news/' . $post->slug, $this->siteHeaders($this->key))
            ->assertNotFound();
    }

    public function test_one_site_cannot_read_another_sites_news(): void
    {
        [$other] = $this->siteWithKey(['news_enabled' => true]);
        Article::create([
            'site_id' => $other->id, 'type' => Article::TYPE_NEWS,
            'title' => 'Their post', 'body' => 'x', 'published_at' => Carbon::now()->subDay(),
        ]);

        $this->assertSame([], $this->feed());
    }

    // ── the detail route ─────────────────────────────────────────────────────

    public function test_a_post_is_readable_by_slug(): void
    {
        $post = $this->article(Article::TYPE_NEWS, 'Regulator fines operator');

        $this->getJson($this->publicBase($this->site) . '/news/' . $post->slug, $this->siteHeaders($this->key))
            ->assertOk()
            ->assertJsonPath('data.title', 'Regulator fines operator')
            ->assertJsonPath('data.type', Article::TYPE_NEWS);
    }

    public function test_a_guide_slug_404s_on_the_news_route(): void
    {
        $guide = $this->article(Article::TYPE_GUIDE, 'A guide');

        $this->getJson($this->publicBase($this->site) . '/news/' . $guide->slug, $this->siteHeaders($this->key))
            ->assertNotFound();
    }

    // ── the admin CRUD ───────────────────────────────────────────────────────

    public function test_admin_can_create_edit_reorder_and_delete_news(): void
    {
        $this->actingAsAdmin();
        $base = "/api/v1/admin/sites/{$this->site->id}/articles?type=news";

        $id = $this->postJson($base, ['title' => 'Draft post', 'body' => 'Body', 'position' => 5])
            ->assertCreated()
            ->json('data.id');

        // Created as news, not as a guide.
        $this->assertSame(Article::TYPE_NEWS, Article::findOrFail($id)->type);

        $this->putJson("/api/v1/admin/sites/{$this->site->id}/articles/{$id}?type=news", [
            'title' => 'Published post', 'body' => 'Body', 'position' => 1,
            'published_at' => Carbon::now()->subHour()->toDateTimeString(),
        ])->assertOk();

        $this->assertSame(['Published post'], $this->feed());
        $this->assertSame(1, Article::findOrFail($id)->position);

        $this->deleteJson("/api/v1/admin/sites/{$this->site->id}/articles/{$id}?type=news")
            ->assertNoContent();
        $this->assertSame([], $this->feed());
    }

    /**
     * The admin listing is PAGINATED.
     *
     * News is ingested on a schedule, so this table grows whether or not
     * anybody is editing — one site was already 120 rows and 111 KB in a single
     * response before this.
     */
    public function test_the_admin_list_is_paginated_at_fifteen(): void
    {
        for ($i = 1; $i <= 18; $i++) {
            $this->article(Article::TYPE_NEWS, "Post {$i}", now()->subDays($i));
        }

        $this->actingAsAdmin();

        $first = $this->getJson("/api/v1/admin/sites/{$this->site->id}/articles?type=news")->assertOk();

        $this->assertCount(15, $first->json('data'));
        $this->assertSame(18, $first->json('meta.total'));
        $this->assertSame(2, $first->json('meta.last_page'));
        $this->assertSame(15, $first->json('meta.per_page'));

        $second = $this->getJson("/api/v1/admin/sites/{$this->site->id}/articles?type=news&page=2")->assertOk();

        $this->assertCount(3, $second->json('data'));

        // No row appears on both pages — an off-by-one in the ordering is the
        // failure that looks like duplicated content rather than a broken pager.
        $this->assertSame(
            [],
            array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')),
        );
    }

    /**
     * `published_count` counts the WHOLE section, not the page in hand.
     *
     * The banner above the table reports how many entries are live, and the
     * guides threshold reads the same number. Counting the current page would
     * understate both the moment a second page existed.
     */
    public function test_the_published_count_spans_every_page(): void
    {
        // 16 live, plus a draft and a hidden one that must not be counted.
        for ($i = 1; $i <= 16; $i++) {
            $this->article(Article::TYPE_NEWS, "Live {$i}", now()->subDays($i));
        }
        // A real draft: the helper substitutes a date for null, so the column
        // is cleared explicitly.
        $this->article(Article::TYPE_NEWS, 'A draft')->update(['published_at' => null]);
        $this->article(Article::TYPE_NEWS, 'Hidden', now()->subDay())->update(['active' => false]);

        $this->actingAsAdmin();

        $res = $this->getJson("/api/v1/admin/sites/{$this->site->id}/articles?type=news")->assertOk();

        $this->assertCount(15, $res->json('data'), 'still one page of rows');
        $this->assertSame(18, $res->json('meta.total'));
        // Both conditions, matching Article::scopeVisible().
        $this->assertSame(16, $res->json('meta.published_count'));
    }

    public function test_the_admin_list_shows_drafts_but_only_of_the_requested_type(): void
    {
        $this->article(Article::TYPE_NEWS, 'News draft', null);
        $this->article(Article::TYPE_GUIDE, 'Guide draft', null);
        $this->actingAsAdmin();

        $news = array_column(
            $this->getJson("/api/v1/admin/sites/{$this->site->id}/articles?type=news")->assertOk()->json('data'),
            'title',
        );
        $guides = array_column(
            $this->getJson("/api/v1/admin/sites/{$this->site->id}/articles?type=guide")->assertOk()->json('data'),
            'title',
        );

        $this->assertSame(['News draft'], $news);
        $this->assertSame(['Guide draft'], $guides);
    }

    /**
     * The ids are shared across both feeds, so without a type check the guides
     * screen could edit or delete a news post it never displayed.
     */
    public function test_one_section_cannot_edit_or_delete_the_others_rows(): void
    {
        $post = $this->article(Article::TYPE_NEWS, 'News post');
        $this->actingAsAdmin();

        $this->getJson("/api/v1/admin/sites/{$this->site->id}/articles/{$post->id}?type=guide")->assertNotFound();
        $this->putJson("/api/v1/admin/sites/{$this->site->id}/articles/{$post->id}?type=guide", [
            'title' => 'Hijacked', 'body' => 'x',
        ])->assertNotFound();
        $this->deleteJson("/api/v1/admin/sites/{$this->site->id}/articles/{$post->id}?type=guide")->assertNotFound();

        $this->assertSame('News post', $post->fresh()->title);
    }

    // ── the five controls the admin screen must provide ─────────────────────

    public function test_hiding_a_post_removes_it_from_the_site_without_losing_anything(): void
    {
        $post = $this->article(Article::TYPE_NEWS, 'Taken down');
        $publishedAt = $post->published_at;
        $this->actingAsAdmin();

        $this->putJson("/api/v1/admin/sites/{$this->site->id}/articles/{$post->id}?type=news", [
            'title' => $post->title, 'body' => $post->body, 'active' => false,
        ])->assertOk();

        $this->assertSame([], $this->feed(), 'A hidden post must not be on the site.');

        // The whole point of a separate switch: nothing else was destroyed.
        $post->refresh();
        $this->assertFalse($post->active);
        $this->assertEquals($publishedAt->timestamp, $post->published_at->timestamp);
        $this->assertSame('Taken down', $post->title);
    }

    public function test_showing_it_again_restores_it_unchanged(): void
    {
        $post = $this->article(Article::TYPE_NEWS, 'Back up');
        $post->update(['active' => false]);
        $this->assertSame([], $this->feed());
        $this->actingAsAdmin();

        $this->putJson("/api/v1/admin/sites/{$this->site->id}/articles/{$post->id}?type=news", [
            'title' => $post->title, 'body' => $post->body, 'active' => true,
        ])->assertOk();

        $this->assertSame(['Back up'], $this->feed());
    }

    /** A hidden post must not be reachable by URL either. */
    public function test_a_hidden_post_404s_on_its_own_url(): void
    {
        $post = $this->article(Article::TYPE_NEWS, 'Hidden');
        $post->update(['active' => false]);

        $this->getJson($this->publicBase($this->site) . '/news/' . $post->slug, $this->siteHeaders($this->key))
            ->assertNotFound();
    }

    public function test_position_can_be_changed_from_the_admin_and_reorders_the_feed(): void
    {
        $first = $this->article(Article::TYPE_NEWS, 'Was first', null, 10);
        $second = $this->article(Article::TYPE_NEWS, 'Was second', null, 20);
        $this->assertSame(['Was first', 'Was second'], $this->feed());

        $this->actingAsAdmin();
        $this->putJson("/api/v1/admin/sites/{$this->site->id}/articles/{$second->id}?type=news", [
            'title' => $second->title, 'body' => $second->body, 'position' => 1,
        ])->assertOk();

        $this->assertSame(['Was second', 'Was first'], $this->feed());
        $this->assertSame(1, $second->fresh()->position);
    }

    /** New posts are shown unless the editor says otherwise. */
    public function test_a_new_post_defaults_to_shown(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson("/api/v1/admin/sites/{$this->site->id}/articles?type=news", [
            'title' => 'Fresh', 'body' => 'Body',
            'published_at' => Carbon::now()->subHour()->toDateTimeString(),
        ])->assertCreated()->json('data.id');

        $this->assertTrue(Article::findOrFail($id)->active);
        $this->assertSame(['Fresh'], $this->feed());
    }

    /** The admin list shows hidden posts — otherwise they could never be restored. */
    public function test_the_admin_list_still_shows_hidden_posts(): void
    {
        $post = $this->article(Article::TYPE_NEWS, 'Hidden');
        $post->update(['active' => false]);
        $this->actingAsAdmin();

        $row = collect($this->getJson("/api/v1/admin/sites/{$this->site->id}/articles?type=news")->assertOk()->json('data'))
            ->firstWhere('title', 'Hidden');

        $this->assertNotNull($row, 'A hidden post must remain editable in the admin.');
        $this->assertFalse($row['active']);
        $this->assertArrayHasKey('position', $row);
    }

    // ── best news (featured) ────────────────────────────────────────────────

    /** @return list<string> titles the home page would show */
    private function featuredFeed(): array
    {
        return array_column(
            $this->getJson($this->publicBase($this->site) . '/news/featured', $this->siteHeaders($this->key))
                ->assertOk()->json('data'),
            'title',
        );
    }

    public function test_only_picked_posts_reach_the_home_page(): void
    {
        $picked = $this->article(Article::TYPE_NEWS, 'Picked');
        $picked->update(['featured' => true]);
        $this->article(Article::TYPE_NEWS, 'Not picked');

        $this->assertSame(['Picked'], $this->featuredFeed());
        // …and the main feed still carries both.
        $this->assertCount(2, $this->feed());
    }

    public function test_nothing_is_picked_by_default(): void
    {
        $this->article(Article::TYPE_NEWS, 'Ordinary');

        $this->assertSame([], $this->featuredFeed());
    }

    /**
     * `featured` must not override visibility. A hidden post that is still
     * flagged as a pick would reappear on the busiest page on the site.
     */
    public function test_a_hidden_pick_does_not_reach_the_home_page(): void
    {
        $post = $this->article(Article::TYPE_NEWS, 'Hidden pick');
        $post->update(['featured' => true, 'active' => false]);

        $this->assertSame([], $this->featuredFeed());
    }

    public function test_a_draft_pick_does_not_reach_the_home_page(): void
    {
        $post = $this->article(Article::TYPE_NEWS, 'Draft pick', null);
        $post->update(['featured' => true]);
        $post->update(['published_at' => null]);

        $this->assertSame([], $this->featuredFeed());
    }

    public function test_the_home_page_strip_is_capped(): void
    {
        foreach (range(1, 11) as $i) {
            $post = $this->article(Article::TYPE_NEWS, "Pick {$i}", Carbon::now()->subDays($i), $i);
            $post->update(['featured' => true]);
        }

        // Two rows of four compact cards on the home page.
        $this->assertCount(8, $this->featuredFeed(), 'The strip must be capped server-side.');
    }

    public function test_picks_honour_position_order(): void
    {
        foreach ([['Second', 20], ['First', 10]] as [$title, $position]) {
            $post = $this->article(Article::TYPE_NEWS, $title, Carbon::now()->subDay(), $position);
            $post->update(['featured' => true]);
        }

        $this->assertSame(['First', 'Second'], $this->featuredFeed());
    }

    /** `featured` is a literal segment and must not be read as a slug. */
    public function test_the_featured_route_is_not_swallowed_by_the_slug_route(): void
    {
        $post = $this->article(Article::TYPE_NEWS, 'Picked');
        $post->update(['featured' => true]);

        $this->getJson($this->publicBase($this->site) . '/news/featured', $this->siteHeaders($this->key))
            ->assertOk()
            // A list, not a single post — proof it hit featured() and not show().
            ->assertJsonPath('data.0.title', 'Picked');
    }

    public function test_featured_404s_when_news_is_switched_off(): void
    {
        $this->site->update(['news_enabled' => false]);

        $this->getJson($this->publicBase($this->site) . '/news/featured', $this->siteHeaders($this->key))
            ->assertNotFound();
    }

    public function test_the_admin_can_pick_and_unpick(): void
    {
        $post = $this->article(Article::TYPE_NEWS, 'Toggle me');
        $this->actingAsAdmin();
        $url = "/api/v1/admin/sites/{$this->site->id}/articles/{$post->id}?type=news";

        $this->putJson($url, ['title' => $post->title, 'body' => $post->body, 'featured' => true])->assertOk();
        $this->assertSame(['Toggle me'], $this->featuredFeed());

        $this->putJson($url, ['title' => $post->title, 'body' => $post->body, 'featured' => false])->assertOk();
        $this->assertSame([], $this->featuredFeed());
    }

    // ── news categories ─────────────────────────────────────────────────────

    private function category(string $name, int $position = 0, bool $active = true): \App\Models\NewsCategory
    {
        return \App\Models\NewsCategory::create([
            'site_id' => $this->site->id, 'name' => $name, 'position' => $position, 'active' => $active,
        ]);
    }

    /** @return array<string, int> category name => visible post count */
    private function topics(): array
    {
        $data = $this->getJson($this->publicBase($this->site) . '/news', $this->siteHeaders($this->key))
            ->assertOk()->json('data.categories');

        return array_column($data, 'articles_count', 'name');
    }

    public function test_a_post_carries_its_category(): void
    {
        $licensing = $this->category('Licensing');
        $post = $this->article(Article::TYPE_NEWS, 'Regulator news');
        $post->update(['news_category_id' => $licensing->id]);

        $row = $this->getJson($this->publicBase($this->site) . '/news', $this->siteHeaders($this->key))
            ->assertOk()->json('data.posts.0');

        $this->assertSame('Licensing', $row['news_category']['name']);
        $this->assertSame('licensing', $row['news_category']['slug']);
    }

    public function test_a_post_without_a_category_still_publishes(): void
    {
        $this->article(Article::TYPE_NEWS, 'Uncategorised');

        $this->assertSame(['Uncategorised'], $this->feed());
    }

    public function test_the_feed_can_be_filtered_by_category(): void
    {
        $licensing = $this->category('Licensing');
        $payments = $this->category('Payments');

        $a = $this->article(Article::TYPE_NEWS, 'Licence story');
        $a->update(['news_category_id' => $licensing->id]);
        $b = $this->article(Article::TYPE_NEWS, 'Payment story');
        $b->update(['news_category_id' => $payments->id]);
        $this->article(Article::TYPE_NEWS, 'No category');

        $this->assertSame(['Licence story'], $this->feed('licensing'));
        $this->assertCount(3, $this->feed());
    }

    /** A stale topic link must show that the topic is gone, not silently show everything. */
    public function test_an_unknown_category_slug_returns_an_empty_feed(): void
    {
        $this->article(Article::TYPE_NEWS, 'Something');

        $this->assertSame([], $this->feed('does-not-exist'));
    }

    /** A pill leading to an empty feed is a broken promise, so it is not offered. */
    public function test_topics_list_only_categories_that_have_visible_posts(): void
    {
        $used = $this->category('Licensing');
        $this->category('Empty section');
        $hidden = $this->category('Switched off', 10, active: false);

        $a = $this->article(Article::TYPE_NEWS, 'Licence story');
        $a->update(['news_category_id' => $used->id]);
        $b = $this->article(Article::TYPE_NEWS, 'Hidden section story');
        $b->update(['news_category_id' => $hidden->id]);

        $this->assertSame(['Licensing' => 1], $this->topics());
    }

    public function test_the_popular_rail_holds_the_picks_and_ignores_the_filter(): void
    {
        $licensing = $this->category('Licensing');
        $payments = $this->category('Payments');

        $pick = $this->article(Article::TYPE_NEWS, 'Picked payment story');
        $pick->update(['featured' => true, 'news_category_id' => $payments->id]);
        $other = $this->article(Article::TYPE_NEWS, 'Licence story');
        $other->update(['news_category_id' => $licensing->id]);

        $popular = array_column(
            $this->getJson($this->publicBase($this->site) . '/news?category=licensing', $this->siteHeaders($this->key))
                ->assertOk()->json('data.popular'),
            'title',
        );

        // Filtered to Licensing, but the rail still offers the way out.
        $this->assertSame(['Picked payment story'], $popular);
    }

    public function test_admin_can_manage_categories(): void
    {
        $this->actingAsAdmin();
        $base = "/api/v1/admin/sites/{$this->site->id}/news-categories";

        $id = $this->postJson($base, ['name' => 'Licensing', 'position' => 10])
            ->assertCreated()->json('data.id');
        $this->assertSame('licensing', \App\Models\NewsCategory::findOrFail($id)->slug);

        $this->putJson("{$base}/{$id}", ['name' => 'Licences & Regulators', 'active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Licences & Regulators')
            ->assertJsonPath('data.active', false);

        // The slug is in /news?category=… — a rename must not move the address.
        $this->assertSame('licensing', \App\Models\NewsCategory::findOrFail($id)->slug);

        $this->assertCount(1, $this->getJson($base)->assertOk()->json('data'));

        $this->deleteJson("{$base}/{$id}")->assertOk();
        $this->assertNull(\App\Models\NewsCategory::find($id));
    }

    public function test_deleting_a_category_leaves_its_posts_published(): void
    {
        $category = $this->category('Licensing');
        $post = $this->article(Article::TYPE_NEWS, 'Survives');
        $post->update(['news_category_id' => $category->id]);
        $this->actingAsAdmin();

        $this->deleteJson("/api/v1/admin/sites/{$this->site->id}/news-categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('orphaned', 1);

        $post->refresh();
        $this->assertNull($post->news_category_id);
        $this->assertSame(['Survives'], $this->feed(), 'The post must stay published.');
    }

    public function test_one_site_cannot_edit_another_sites_category(): void
    {
        [$other] = $this->siteWithKey(['news_enabled' => true]);
        $theirs = \App\Models\NewsCategory::create(['site_id' => $other->id, 'name' => 'Theirs']);
        $this->actingAsAdmin();

        $this->putJson("/api/v1/admin/sites/{$this->site->id}/news-categories/{$theirs->id}", ['name' => 'Hijacked'])
            ->assertNotFound();
        $this->deleteJson("/api/v1/admin/sites/{$this->site->id}/news-categories/{$theirs->id}")
            ->assertNotFound();

        $this->assertSame('Theirs', $theirs->fresh()->name);
    }

    // ── reading time ────────────────────────────────────────────────────────

    public function test_reading_time_is_computed_from_the_body(): void
    {
        $post = Article::create([
            'site_id' => $this->site->id, 'type' => Article::TYPE_NEWS,
            'title' => 'Long read', 'published_at' => Carbon::now()->subDay(),
            // 600 words at 200 wpm = 3 minutes.
            'body' => '<p>' . implode(' ', array_fill(0, 600, 'word')) . '</p>',
        ]);

        $this->assertSame(3, $post->fresh()->read_minutes);
    }

    /** Markup must not be counted as words. */
    public function test_html_tags_are_not_counted(): void
    {
        $plain = str_repeat('word ', 200);

        $bare = Article::create([
            'site_id' => $this->site->id, 'type' => Article::TYPE_NEWS,
            'title' => 'Bare', 'body' => $plain,
        ]);
        $marked = Article::create([
            'site_id' => $this->site->id, 'type' => Article::TYPE_NEWS,
            'title' => 'Marked up',
            'body' => '<div class="x"><p><strong>' . $plain . '</strong></p></div>',
        ]);

        $this->assertSame($bare->fresh()->read_minutes, $marked->fresh()->read_minutes);
    }

    /** An article with no text gets no claim about how long it takes to read. */
    public function test_an_empty_body_has_no_reading_time(): void
    {
        $post = Article::create([
            'site_id' => $this->site->id, 'type' => Article::TYPE_NEWS,
            'title' => 'Nothing yet', 'body' => '',
        ]);

        $this->assertNull($post->fresh()->read_minutes);
    }

    /** Anything with words rounds UP to a minute — never "0 min read". */
    public function test_a_very_short_post_is_one_minute(): void
    {
        $post = Article::create([
            'site_id' => $this->site->id, 'type' => Article::TYPE_NEWS,
            'title' => 'Tiny', 'body' => '<p>Three words here.</p>',
        ]);

        $this->assertSame(1, $post->fresh()->read_minutes);
    }

    public function test_reading_time_is_recomputed_when_the_body_changes(): void
    {
        $post = Article::create([
            'site_id' => $this->site->id, 'type' => Article::TYPE_NEWS,
            'title' => 'Grows', 'body' => '<p>Short.</p>',
        ]);
        $this->assertSame(1, $post->fresh()->read_minutes);

        $post->update(['body' => '<p>' . implode(' ', array_fill(0, 1000, 'word')) . '</p>']);

        $this->assertSame(5, $post->fresh()->read_minutes);
    }

    public function test_the_feed_carries_reading_time(): void
    {
        Article::create([
            'site_id' => $this->site->id, 'type' => Article::TYPE_NEWS,
            'title' => 'With body', 'published_at' => Carbon::now()->subDay(),
            'body' => '<p>' . implode(' ', array_fill(0, 400, 'word')) . '</p>',
        ]);

        $row = $this->getJson($this->publicBase($this->site) . '/news', $this->siteHeaders($this->key))
            ->assertOk()->json('data.posts.0');

        $this->assertSame(2, $row['read_minutes']);
    }

    /** It is derived, so a request must not be able to dictate it. */
    public function test_reading_time_cannot_be_set_through_the_api(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson("/api/v1/admin/sites/{$this->site->id}/articles?type=news", [
            'title' => 'Claimed', 'body' => '<p>Three words here.</p>', 'read_minutes' => 99,
        ])->assertCreated()->json('data.id');

        $this->assertSame(1, Article::findOrFail($id)->read_minutes);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $this->actingAsAdmin();

        $this->getJson("/api/v1/admin/sites/{$this->site->id}/articles?type=blog")->assertNotFound();
    }

    /** Existing callers predate news and must keep getting guides. */
    public function test_omitting_the_type_still_means_guides(): void
    {
        $this->article(Article::TYPE_NEWS, 'News post');
        $this->article(Article::TYPE_GUIDE, 'Guide post');
        $this->actingAsAdmin();

        $titles = array_column(
            $this->getJson("/api/v1/admin/sites/{$this->site->id}/articles")->assertOk()->json('data'),
            'title',
        );

        $this->assertSame(['Guide post'], $titles);
    }

    /** Every row that existed before this feature is a guide. */
    public function test_type_defaults_to_guide(): void
    {
        $article = Article::create([
            'site_id' => $this->site->id, 'title' => 'Legacy row', 'body' => 'x',
        ]);

        $this->assertSame(Article::TYPE_GUIDE, $article->fresh()->type);
    }
}
