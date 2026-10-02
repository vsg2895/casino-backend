<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BonusCategory;
use App\Models\Casino;
use App\Models\SearchIndexEntry;
use App\Models\Site;
use App\Models\SpecialOffer;
use App\Services\Search\SearchIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Bonus categories in site search.
 *
 * A bonus category has no visibility of its own: it is searchable on a site
 * exactly while that site publishes a claimable offer filed under it, AND has
 * the Bonus area switched on. Both halves matter — the public Bonus endpoint
 * 404s without `bonus_enabled`, so a suggestion on such a site would be a link
 * to nothing, and a heading whose last offer was hidden is a heading that no
 * longer exists on the page.
 *
 * DRIVER NOTE, as in SearchSuggestTest: the suite runs on SQLite, so matching
 * goes through the prefix path rather than FULLTEXT. What these tests are about
 * is which rows exist and for which sites, which is driver-independent.
 */
class SearchBonusCategoryTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /**
     * A site with the Bonus area on, a casino attached to it, and one claimable
     * offer filed under a bonus category.
     *
     * @return array{0: Site, 1: string, 2: BonusCategory, 3: SpecialOffer}
     */
    private function siteWithBonusOffer(string $categoryName = 'Free Spins'): array
    {
        [$site, $key] = $this->siteWithKey();
        $site->forceFill(['bonus_enabled' => true])->save();

        $casino = Casino::factory()->create(['active' => true]);
        $casino->sites()->attach($site->id, ['affiliate_url' => 'https://x.test', 'active' => true]);

        $category = BonusCategory::create(['name' => $categoryName, 'active' => true]);

        $offer = SpecialOffer::factory()->create([
            'casino_id'         => $casino->id,
            'bonus_category_id' => $category->id,
            'active'            => true,
            'expires_at'        => null,
        ]);

        // Pivot writes fire no model event, so trigger the sync the way the
        // admin controllers do.
        app(SearchIndexer::class)->syncCasino($casino->fresh());

        return [$site, $key, $category->fresh(), $offer->fresh()];
    }

    private function rowsFor(Site $site, BonusCategory $category): int
    {
        return SearchIndexEntry::query()
            ->where('site_id', $site->id)
            ->where('section', 'bonus_categories')
            ->where('searchable_id', $category->id)
            ->count();
    }

    public function test_a_bonus_category_is_indexed_for_a_site_that_publishes_an_offer_under_it(): void
    {
        [$site, , $category] = $this->siteWithBonusOffer();

        $this->assertSame(1, $this->rowsFor($site, $category));
    }

    public function test_it_is_suggested_in_its_own_section(): void
    {
        [$site, $key, $category] = $this->siteWithBonusOffer('Reload Bonuses');

        $response = $this->getJson(
            $this->publicBase($site) . '/search/suggest?q=Reload',
            $this->siteHeaders($key),
        )->assertOk();

        $keys = array_column($response->json('sections'), 'key');
        $this->assertContains('bonus_categories', $keys);

        $group = collect($response->json('sections'))->firstWhere('key', 'bonus_categories');
        $this->assertSame('Bonus Categories', $group['label']);
        $this->assertSame($category->name, $group['items'][0]['title']);
    }

    public function test_the_suggestion_points_at_the_listing_anchor(): void
    {
        // The offers listing renders every bonus section with id="bonus-<slug>",
        // so that page plus the anchor is the one address a category always has.
        [$site, $key, $category] = $this->siteWithBonusOffer('No Deposit Offers');

        $response = $this->getJson(
            $this->publicBase($site) . '/search/suggest?q=No%20Deposit',
            $this->siteHeaders($key),
        )->assertOk();

        $group = collect($response->json('sections'))->firstWhere('key', 'bonus_categories');

        $this->assertSame('/special-offers#bonus-' . $category->slug, $group['items'][0]['url']);
    }

    public function test_a_site_without_the_bonus_area_never_gets_one(): void
    {
        // Its public Bonus endpoint 404s, so a suggestion would link to nothing.
        [$site, , $category] = $this->siteWithBonusOffer();

        $site->forceFill(['bonus_enabled' => false])->save();
        app(SearchIndexer::class)->syncBonusCategory($category);

        $this->assertSame(0, $this->rowsFor($site, $category));
    }

    public function test_hiding_the_last_offer_removes_the_category(): void
    {
        // The heading disappears from the page when its last offer is hidden, so
        // it must disappear from search at the same moment.
        [$site, , $category, $offer] = $this->siteWithBonusOffer();

        $offer->forceFill(['active' => false])->save();
        app(SearchIndexer::class)->syncSpecialOffer($offer->fresh());

        $this->assertSame(0, $this->rowsFor($site, $category));
    }

    public function test_an_expired_offer_does_not_keep_the_category_alive(): void
    {
        [$site, , $category, $offer] = $this->siteWithBonusOffer();

        $offer->forceFill(['expires_at' => now()->subDay()])->save();
        app(SearchIndexer::class)->syncSpecialOffer($offer->fresh());

        $this->assertSame(0, $this->rowsFor($site, $category));
    }

    public function test_deactivating_the_category_clears_it(): void
    {
        [$site, , $category] = $this->siteWithBonusOffer();

        $category->forceFill(['active' => false])->save();

        $this->assertSame(0, $this->rowsFor($site, $category), 'the observer should have cleared the rows');
    }

    public function test_it_is_not_leaked_to_another_site(): void
    {
        [$site, , $category] = $this->siteWithBonusOffer();
        [$other] = $this->siteWithKey();
        $other->forceFill(['bonus_enabled' => true])->save();

        app(SearchIndexer::class)->syncBonusCategory($category);

        $this->assertSame(1, $this->rowsFor($site, $category));
        $this->assertSame(0, $this->rowsFor($other, $category), 'the other site publishes no offer under it');
    }

    public function test_the_offers_section_is_labelled_bonuses(): void
    {
        [$site, $key] = $this->siteWithBonusOffer();

        $response = $this->getJson(
            $this->publicBase($site) . '/search/suggest?q=Free',
            $this->siteHeaders($key),
        )->assertOk();

        $labels = array_column($response->json('sections'), 'label');
        $this->assertNotContains('Special Offers', $labels);
    }
}
