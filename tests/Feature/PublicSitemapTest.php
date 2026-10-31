<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Casino;
use App\Models\Category;
use App\Models\Site;
use App\Models\SpecialOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * GET /sitemap — the indexable detail URLs of ONE site.
 *
 * Each exclusion below is a URL the page itself would refuse (404 or noindex);
 * submitting it would make the sitemap contradict the page header.
 */
class PublicSitemapTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Observers queue a revalidation POST to the site; never let it leave.
        Http::fake();
    }

    private function attachedCasino(Site $site, array $attrs = [], bool $activeOnSite = true): Casino
    {
        $casino = Casino::factory()->create($attrs);
        $casino->sites()->attach($site->id, ['affiliate_url' => 'https://x.test/go', 'active' => $activeOnSite]);

        return $casino;
    }

    private function article(Site $site, string $type, array $attrs = []): Article
    {
        return Article::create([
            'site_id'      => $site->id,
            'type'         => $type,
            'title'        => fake()->unique()->sentence(4),
            'body'         => 'Body copy.',
            'published_at' => Carbon::now()->subDay(),
            ...$attrs,
        ]);
    }

    /** @return array<string, list<string>> slugs per section */
    private function sitemap(Site $site, string $key): array
    {
        $data = $this->getJson($this->publicBase($site) . '/sitemap', $this->siteHeaders($key))
            ->assertOk()
            ->json('data');

        return [
            'casinos'    => array_column($data['casinos'], 'slug'),
            'categories' => array_column($data['categories'], 'slug'),
            'offers'     => array_column($data['offers'], 'slug'),
            'articles'   => array_map(static fn (array $a): string => $a['type'] . ':' . $a['slug'], $data['articles']),
        ];
    }

    public function test_requires_the_site_key(): void
    {
        [$site] = $this->siteWithKey();

        $this->getJson($this->publicBase($site) . '/sitemap')->assertUnauthorized();
    }

    public function test_entries_carry_only_slug_and_updated_at(): void
    {
        [$site, $key] = $this->siteWithKey();
        $casino = $this->attachedCasino($site);

        $this->getJson($this->publicBase($site) . '/sitemap', $this->siteHeaders($key))
            ->assertOk()
            ->assertExactJson(['data' => [
                'casinos'    => [['slug' => $casino->slug, 'updated_at' => $casino->updated_at->toISOString()]],
                'categories' => [],
                'offers'     => [],
                'articles'   => [],
                'authors'    => [],
            ]]);
    }

    public function test_casinos_follow_attachment_activity_and_noindex(): void
    {
        [$site, $key] = $this->siteWithKey();
        [$other] = $this->siteWithKey();

        $live = $this->attachedCasino($site);
        $offOnSite = $this->attachedCasino($site, [], activeOnSite: false);
        $offGlobally = $this->attachedCasino($site, ['active' => false]);
        $hidden = $this->attachedCasino($site, ['noindex' => true]);
        $elsewhere = $this->attachedCasino($other);

        $this->assertSame([$live->slug], $this->sitemap($site, $key)['casinos']);
        $this->assertNotContains($offOnSite->slug, $this->sitemap($site, $key)['casinos']);
        $this->assertNotContains($offGlobally->slug, $this->sitemap($site, $key)['casinos']);
        $this->assertNotContains($hidden->slug, $this->sitemap($site, $key)['casinos']);
        $this->assertNotContains($elsewhere->slug, $this->sitemap($site, $key)['casinos']);
    }

    public function test_categories_need_a_visible_casino_and_no_noindex(): void
    {
        [$site, $key] = $this->siteWithKey();
        [$other] = $this->siteWithKey();

        $filled = Category::factory()->create();
        $filled->casinos()->attach($this->attachedCasino($site)->id);

        $onlyInactive = Category::factory()->create();
        $onlyInactive->casinos()->attach($this->attachedCasino($site, ['active' => false])->id);

        $onlyElsewhere = Category::factory()->create();
        $onlyElsewhere->casinos()->attach($this->attachedCasino($other)->id);

        $hidden = Category::factory()->create(['noindex' => true]);
        $hidden->casinos()->attach($this->attachedCasino($site)->id);

        $this->assertSame([$filled->slug], $this->sitemap($site, $key)['categories']);
    }

    public function test_offers_follow_the_public_listing_rules_and_noindex(): void
    {
        [$site, $key] = $this->siteWithKey();
        $casino = $this->attachedCasino($site);

        $live = SpecialOffer::factory()->for($casino)->create();
        SpecialOffer::factory()->for($casino)->create(['active' => false]);
        SpecialOffer::factory()->for($casino)->create(['expires_at' => Carbon::now()->subWeek()]);
        SpecialOffer::factory()->for($casino)->create(['noindex' => true]);
        SpecialOffer::factory()->for($this->attachedCasino($site, ['active' => false]))->create();

        $this->assertSame([$live->slug], $this->sitemap($site, $key)['offers']);
    }

    public function test_news_lists_visible_indexable_posts_of_this_site_only(): void
    {
        [$site, $key] = $this->siteWithKey(['news_enabled' => true]);
        [$other] = $this->siteWithKey(['news_enabled' => true]);

        $live = $this->article($site, Article::TYPE_NEWS);
        $this->article($site, Article::TYPE_NEWS, ['published_at' => Carbon::now()->addDay()]);
        $this->article($site, Article::TYPE_NEWS, ['published_at' => null]);
        $this->article($site, Article::TYPE_NEWS, ['active' => false]);
        $this->article($site, Article::TYPE_NEWS, ['noindex' => true]);
        $this->article($other, Article::TYPE_NEWS);

        $this->assertSame(['news:' . $live->slug], $this->sitemap($site, $key)['articles']);
    }

    public function test_a_switched_off_section_lists_no_articles(): void
    {
        [$site, $key] = $this->siteWithKey(['news_enabled' => false, 'guides_enabled' => false]);
        $this->article($site, Article::TYPE_NEWS);

        $this->assertSame([], $this->sitemap($site, $key)['articles']);
    }

    public function test_guides_appear_only_once_the_section_is_open(): void
    {
        [$site, $key] = $this->siteWithKey(['guides_enabled' => true, 'news_enabled' => false]);

        $first = $this->article($site, Article::TYPE_GUIDE);
        $second = $this->article($site, Article::TYPE_GUIDE);
        $this->assertSame([], $this->sitemap($site, $key)['articles'], 'below the threshold /guides 404s');

        // The third guide opens the section even though it is itself noindexed:
        // the threshold is about whether the pages exist, not which are submitted.
        Cache::flush();
        $this->article($site, Article::TYPE_GUIDE, ['noindex' => true]);

        $listed = $this->sitemap($site, $key)['articles'];
        sort($listed);
        $expected = ['guide:' . $first->slug, 'guide:' . $second->slug];
        sort($expected);

        $this->assertSame($expected, $listed);
    }

    public function test_an_admin_noindex_toggle_drops_the_casino_without_waiting_for_the_cache(): void
    {
        [$site, $key] = $this->siteWithKey();
        $casino = $this->attachedCasino($site);
        $this->assertSame([$casino->slug], $this->sitemap($site, $key)['casinos']);

        // CasinoObserver flushes the site's cache on save, so the cached copy
        // from the request above must not survive the change.
        $casino->update(['noindex' => true]);

        $this->assertSame([], $this->sitemap($site, $key)['casinos']);
    }

    public function test_the_admin_category_switch_reaches_the_sitemap_and_the_public_payload(): void
    {
        [$site, $key] = $this->siteWithKey();
        $category = Category::factory()->create();
        $category->casinos()->attach($this->attachedCasino($site)->id);
        $this->assertSame([$category->slug], $this->sitemap($site, $key)['categories']);

        $this->actingAsAdmin();
        $this->putJson('/api/v1/admin/categories/' . $category->id, ['noindex' => true])
            ->assertOk()
            ->assertJsonPath('data.noindex', true);

        $this->assertSame([], $this->sitemap($site, $key)['categories']);
        $this->getJson($this->publicBase($site) . '/categories/' . $category->slug, $this->siteHeaders($key))
            ->assertOk()
            ->assertJsonPath('data.category.noindex', true);
    }
}
