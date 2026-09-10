<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\CasinoReview;
use App\Models\Category;
use App\Models\CmsPage;
use App\Models\SearchIndexEntry;
use App\Models\Site;
use App\Models\SpecialOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The suggest endpoint and the index-sync behaviour behind it.
 *
 * NOTE ON THE DRIVER: the suite runs on SQLite, which has no FULLTEXT, so
 * SearchService serves every query here through its prefix path. That covers
 * scoping, validation, pagination and moderation sync — the logic these tests
 * exist for. The FULLTEXT ranking path is MySQL-only and is verified against
 * MySQL with EXPLAIN, not here; claiming otherwise would be testing a query
 * production never runs.
 */
class SearchSuggestTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @return array{0: Site, 1: string, 2: Casino} */
    private function siteWithCasino(string $name = 'Gamma Casino'): array
    {
        [$site, $key] = $this->siteWithKey();

        $casino = Casino::factory()->create(['name' => $name, 'active' => true]);
        $casino->sites()->attach($site->id, ['affiliate_url' => 'https://x.test', 'active' => true]);
        // The pivot write fires no model event, so trigger the sync the way the
        // admin controllers do.
        app(\App\Services\Search\SearchIndexer::class)->syncCasino($casino->fresh());

        return [$site, $key, $casino];
    }

    public function test_it_requires_a_site_key(): void
    {
        [$site] = $this->siteWithKey();

        $this->getJson($this->publicBase($site) . '/search/suggest?q=gam')
            ->assertUnauthorized();
    }

    public function test_it_validates_the_query(): void
    {
        [$site, $key] = $this->siteWithKey();
        $base = $this->publicBase($site) . '/search/suggest';

        $this->getJson($base, $this->siteHeaders($key))->assertStatus(422);
        $this->getJson($base . '?q=' . str_repeat('a', 101), $this->siteHeaders($key))->assertStatus(422);
        $this->getJson($base . '?q=gam&section=nope', $this->siteHeaders($key))->assertStatus(422);
        $this->getJson($base . '?q=gam&page=0', $this->siteHeaders($key))->assertStatus(422);
    }

    public function test_it_finds_a_casino_and_groups_it_by_section(): void
    {
        [$site, $key, $casino] = $this->siteWithCasino();

        $response = $this->getJson(
            $this->publicBase($site) . '/search/suggest?q=Gam',
            $this->siteHeaders($key),
        )->assertOk();

        $response->assertJsonPath('sections.0.key', 'casinos');
        $response->assertJsonPath('sections.0.items.0.title', $casino->name);
        $response->assertJsonPath('sections.0.items.0.url', '/casinos/' . $casino->slug);
        $response->assertJsonPath('sections.0.items.0.section_label', 'Casinos');
    }

    public function test_one_sites_content_never_appears_in_anothers_results(): void
    {
        [, , $casino] = $this->siteWithCasino('Gamma Casino');
        [$other, $otherKey] = $this->siteWithKey();

        // The casino is attached to the FIRST site only.
        $this->getJson(
            $this->publicBase($other) . '/search/suggest?q=Gam',
            $this->siteHeaders($otherKey),
        )->assertOk()->assertJsonPath('total', 0);

        $this->assertSame(
            0,
            SearchIndexEntry::where('site_id', $other->id)->where('searchable_id', $casino->id)->count(),
        );
    }

    public function test_deactivating_a_casino_removes_it_and_its_children(): void
    {
        [$site, $key, $casino] = $this->siteWithCasino();

        $offer = SpecialOffer::factory()->create([
            'casino_id' => $casino->id, 'title' => 'Gamma Welcome Bonus', 'active' => true,
        ]);
        $review = CasinoReview::create([
            'site_id' => $site->id, 'casino_id' => $casino->id, 'author_name' => 'A',
            'rating' => 5, 'title' => 'Gamma is great', 'body' => str_repeat('good ', 10),
            'status' => CasinoReview::STATUS_PUBLISHED,
        ]);

        $this->assertGreaterThan(0, SearchIndexEntry::where('site_id', $site->id)->count());

        $casino->update(['active' => false]);

        $this->assertSame(0, SearchIndexEntry::where('searchable_id', $casino->id)
            ->where('searchable_type', Casino::class)->count());
        $this->assertSame(0, SearchIndexEntry::where('searchable_id', $offer->id)
            ->where('searchable_type', SpecialOffer::class)->count(), 'offer should cascade out');
        $this->assertSame(0, SearchIndexEntry::where('searchable_id', $review->id)
            ->where('searchable_type', CasinoReview::class)->count(), 'review should cascade out');

        $this->getJson($this->publicBase($site) . '/search/suggest?q=Gam', $this->siteHeaders($key))
            ->assertOk()->assertJsonPath('total', 0);
    }

    public function test_renaming_a_casino_updates_the_indexed_title(): void
    {
        [$site, $key, $casino] = $this->siteWithCasino();

        $casino->update(['name' => 'Delta Casino']);

        $this->getJson($this->publicBase($site) . '/search/suggest?q=Delta', $this->siteHeaders($key))
            ->assertOk()->assertJsonPath('sections.0.items.0.title', 'Delta Casino');

        // And the old name is gone, not merely shadowed by the new row.
        $this->getJson($this->publicBase($site) . '/search/suggest?q=Gamma', $this->siteHeaders($key))
            ->assertOk()->assertJsonPath('total', 0);
    }

    public function test_a_review_appears_only_while_published(): void
    {
        [$site, $key, $casino] = $this->siteWithCasino();

        $review = CasinoReview::create([
            'site_id' => $site->id, 'casino_id' => $casino->id, 'author_name' => 'Reviewer',
            'rating' => 5, 'title' => 'Zeta headline', 'body' => 'a perfectly ordinary review body',
            'status' => CasinoReview::STATUS_PENDING,
        ]);

        $url = $this->publicBase($site) . '/search/suggest?q=Zeta';

        // pending -> invisible
        $this->getJson($url, $this->siteHeaders($key))->assertOk()->assertJsonPath('total', 0);

        // published -> visible, in the forum section, with the casino as subtitle
        $review->setPublished(true);
        $res = $this->getJson($url, $this->siteHeaders($key))->assertOk();
        $res->assertJsonPath('sections.0.key', 'forum');
        $res->assertJsonPath('sections.0.items.0.subtitle', $casino->name);
        $res->assertJsonPath('sections.0.items.0.url', '/forum#casino-' . $casino->slug);

        // hidden -> gone again
        $review->setPublished(false);
        $this->getJson($url, $this->siteHeaders($key))->assertOk()->assertJsonPath('total', 0);
    }

    public function test_a_titleless_review_still_renders_a_usable_label(): void
    {
        [$site, $key, $casino] = $this->siteWithCasino();

        $review = CasinoReview::create([
            'site_id' => $site->id, 'casino_id' => $casino->id, 'author_name' => 'R',
            'rating' => 4, 'title' => null, 'body' => 'no headline was given here',
            'status' => CasinoReview::STATUS_PUBLISHED,
        ]);

        $entry = SearchIndexEntry::where('searchable_type', CasinoReview::class)
            ->where('searchable_id', $review->id)->firstOrFail();

        $this->assertSame('Review of ' . $casino->name, $entry->title);
        $this->assertNotSame('', trim($entry->title));
    }

    public function test_the_index_never_stores_reviewer_email(): void
    {
        [$site, , $casino] = $this->siteWithCasino();

        CasinoReview::create([
            'site_id' => $site->id, 'casino_id' => $casino->id, 'author_name' => 'R',
            'author_email' => 'private@example.com', 'rating' => 5, 'title' => 'Nice',
            'body' => 'body text', 'status' => CasinoReview::STATUS_PUBLISHED,
        ]);

        foreach (SearchIndexEntry::all() as $row) {
            $this->assertStringNotContainsString('private@example.com', json_encode($row->toArray()));
        }
    }

    public function test_unpublishing_a_page_removes_it(): void
    {
        [$site, $key] = $this->siteWithKey();

        $page = CmsPage::factory()->create([
            'site_id' => $site->id, 'title' => 'Omega Policy',
            'status' => CmsPage::STATUS_PUBLISHED,
        ]);

        $url = $this->publicBase($site) . '/search/suggest?q=Omega';
        $this->getJson($url, $this->siteHeaders($key))->assertOk()->assertJsonPath('sections.0.key', 'pages');

        $page->update(['status' => CmsPage::STATUS_DRAFT]);
        $this->getJson($url, $this->siteHeaders($key))->assertOk()->assertJsonPath('total', 0);
    }

    public function test_a_single_section_paginates(): void
    {
        [$site, $key, $casino] = $this->siteWithCasino();

        // Seven offers, so page 1 holds five and page 2 holds two.
        for ($i = 1; $i <= 7; $i++) {
            SpecialOffer::factory()->create([
                'casino_id' => $casino->id, 'title' => "Sigma Offer {$i}", 'active' => true,
            ]);
        }

        $base = $this->publicBase($site) . '/search/suggest?q=Sigma&section=special_offers';

        $first = $this->getJson($base . '&page=1', $this->siteHeaders($key))->assertOk();
        $first->assertJsonPath('sections.0.total', 7);
        $first->assertJsonCount(5, 'sections.0.items');
        $first->assertJsonPath('has_more', true);

        $second = $this->getJson($base . '&page=2', $this->siteHeaders($key))->assertOk();
        $second->assertJsonCount(2, 'sections.0.items');
        $second->assertJsonPath('has_more', false);
    }

    public function test_operator_characters_do_not_break_the_query(): void
    {
        [$site, $key] = $this->siteWithCasino();

        foreach (['*', '"', '+-><()~@', 'gam*', '"drop table"'] as $q) {
            $this->getJson(
                $this->publicBase($site) . '/search/suggest?q=' . urlencode($q),
                $this->siteHeaders($key),
            )->assertOk();
        }
    }
}
