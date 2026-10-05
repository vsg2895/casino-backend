<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\Category;
use App\Models\Continent;
use App\Models\Country;
use App\Models\Site;
use Database\Seeders\WorldwideCountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Every count a visitor can see must equal the casinos they get by clicking it.
 *
 * Two numbers are rendered on the public listings: the figure on a category chip
 * ("Betting 3") and the figure on a country card. Both are computed by one query
 * and then CHECKED by a second — so a count is only correct if the listing it
 * promises returns exactly that many rows. These tests assert the two agree
 * through the HTTP endpoints, which is the only place the disagreement was
 * visible.
 *
 * The bug they lock down: the country card counted Worldwide casinos (they are
 * listed on every country page, which is what the wildcard row means) while
 * filtering a CATEGORY by that same country did not. A country whose only
 * casinos were worldwide therefore advertised "1" on its card and then produced
 * no categories at all — and every other country's chips sat exactly one
 * worldwide casino below its card.
 */
class CategoryCountryCountTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    private string $key;

    private Country $worldwide;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->site, $this->key] = $this->siteWithKey(['countries_enabled' => true]);
        $this->seed(WorldwideCountrySeeder::class);
        $this->worldwide = Country::where('slug', Country::WORLDWIDE_SLUG)->firstOrFail();
    }

    private function country(string $name, string $slug): Country
    {
        $continent = Continent::firstOrCreate(['slug' => 'europe'], ['name' => 'Europe', 'position' => 10]);

        return Country::create([
            'continent_id' => $continent->id,
            'name'         => $name,
            'slug'         => $slug,
            'code'         => mb_strtoupper(mb_substr($slug, 0, 2)),
            'position'     => 10,
            'active'       => true,
        ]);
    }

    /**
     * A casino visible on the test site, in the given categories and countries.
     *
     * @param  list<Category>  $categories
     * @param  list<Country>  $countries
     */
    private function casino(string $name, array $categories, array $countries, bool $visible = true): Casino
    {
        $casino = Casino::factory()->create(['name' => $name, 'active' => true]);
        $casino->sites()->attach($this->site->id, [
            'active'        => $visible,
            'position'      => 1,
            'affiliate_url' => 'https://example.test/go',
        ]);
        $casino->categories()->attach(array_column($categories, 'id'));
        $casino->countries()->attach(array_column($countries, 'id'));

        return $casino;
    }

    /** @return array<string, int> chip label => count */
    private function chips(?string $country = null): array
    {
        $response = $this->getJson(
            $this->publicBase($this->site) . '/categories' . ($country ? '?country=' . $country : ''),
            $this->siteHeaders($this->key),
        )->assertOk();

        return array_column($response->json('data'), 'casinos_count', 'slug');
    }

    /** The number of casinos the category listing actually returns. */
    private function listingTotal(string $categorySlug, ?string $country = null): int
    {
        $query = $country ? '?country=' . $country : '';

        return (int) $this->getJson(
            $this->publicBase($this->site) . '/categories/' . $categorySlug . $query,
            $this->siteHeaders($this->key),
        )->assertOk()->json('data.meta.total');
    }

    /** The number on a country's card in the /countries grid. */
    private function countryCard(string $slug, ?string $category = null): ?int
    {
        $grid = $this->getJson(
            $this->publicBase($this->site) . '/countries' . ($category ? '?category=' . $category : ''),
            $this->siteHeaders($this->key),
        )->assertOk()->json('data');

        foreach ($grid as $continent) {
            foreach ($continent['countries'] ?? [] as $country) {
                if ($country['slug'] === $slug) {
                    return (int) $country['casinos_count'];
                }
            }
        }

        return null;
    }

    private function countryListingTotal(string $slug): int
    {
        return (int) $this->getJson(
            $this->publicBase($this->site) . '/countries/' . $slug,
            $this->siteHeaders($this->key),
        )->assertOk()->json('data.meta.total');
    }

    /**
     * @param  array<string, int>  $chips
     * @return array<string, int>
     */
    private function sorted(array $chips): array
    {
        ksort($chips);

        return $chips;
    }

    /**
     * Assert every chip — filtered or not — equals its own listing's total.
     */
    private function assertChipsMatchListings(?string $country = null): void
    {
        $chips = $this->chips($country);
        $this->assertNotEmpty($chips, 'Expected at least one chip to check.');

        foreach ($chips as $slug => $count) {
            $this->assertSame(
                $this->listingTotal($slug, $country),
                $count,
                "Chip '{$slug}' advertises {$count} casinos" . ($country ? " in {$country}" : '') . ' but its listing returns a different number.',
            );
        }
    }

    // ── unfiltered ───────────────────────────────────────────────────────────

    public function test_a_chip_count_equals_the_casinos_its_listing_returns(): void
    {
        $popular = Category::factory()->create(['name' => 'Most Popular', 'slug' => 'most-popular']);
        $betting = Category::factory()->create(['name' => 'Betting', 'slug' => 'betting']);
        $germany = $this->country('Germany', 'germany');

        $this->casino('One', [$popular, $betting], [$germany]);
        $this->casino('Two', [$popular], [$germany]);
        $this->casino('Three', [$popular], [$this->worldwide]);

        // Compared by key, not order: the chip row is ordered by sort_order and
        // this test is about the numbers, not the arrangement.
        $this->assertSame(['betting' => 1, 'most-popular' => 3], $this->sorted($this->chips()));
        $this->assertChipsMatchListings();
    }

    public function test_a_casino_hidden_on_this_site_is_in_neither_the_count_nor_the_listing(): void
    {
        $popular = Category::factory()->create(['name' => 'Most Popular', 'slug' => 'most-popular']);
        $germany = $this->country('Germany', 'germany');

        $this->casino('Visible', [$popular], [$germany]);
        $this->casino('Hidden', [$popular], [$germany], visible: false);

        $this->assertSame(1, $this->chips()['most-popular']);
        $this->assertChipsMatchListings();
    }

    // ── filtered by country ──────────────────────────────────────────────────

    public function test_a_country_filtered_chip_counts_worldwide_casinos(): void
    {
        $popular = Category::factory()->create(['name' => 'Most Popular', 'slug' => 'most-popular']);
        $germany = $this->country('Germany', 'germany');
        $france = $this->country('France', 'france');

        $this->casino('German Casino', [$popular], [$germany]);
        $this->casino('Global Casino', [$popular], [$this->worldwide]);
        $this->casino('French Casino', [$popular], [$france]);

        // Germany's own casino plus the worldwide one — the same two the
        // country page lists, and never the French one.
        $this->assertSame(2, $this->chips('germany')['most-popular']);
        $this->assertChipsMatchListings('germany');
    }

    public function test_a_casino_in_both_the_country_and_worldwide_is_counted_once(): void
    {
        $popular = Category::factory()->create(['name' => 'Most Popular', 'slug' => 'most-popular']);
        $germany = $this->country('Germany', 'germany');

        $this->casino('Both Casino', [$popular], [$germany, $this->worldwide]);

        $this->assertSame(1, $this->chips('germany')['most-popular']);
        $this->assertChipsMatchListings('germany');
    }

    /**
     * The exact shape of the reported bug: the card promises casinos and the
     * filtered page offered no categories to show them in.
     */
    public function test_a_country_whose_only_casinos_are_worldwide_still_has_categories(): void
    {
        $popular = Category::factory()->create(['name' => 'Most Popular', 'slug' => 'most-popular']);
        $germany = $this->country('Germany', 'germany');

        $this->casino('Global Casino', [$popular], [$this->worldwide]);

        $this->assertSame(1, $this->countryCard('germany'), 'The card must count the worldwide casino.');
        $this->assertSame(['most-popular' => 1], $this->sorted($this->chips('germany')));
        $this->assertChipsMatchListings('germany');
    }

    public function test_the_country_card_agrees_with_both_of_its_listings(): void
    {
        $popular = Category::factory()->create(['name' => 'Most Popular', 'slug' => 'most-popular']);
        $germany = $this->country('Germany', 'germany');

        $this->casino('German Casino', [$popular], [$germany]);
        $this->casino('Both Casino', [$popular], [$germany, $this->worldwide]);
        $this->casino('Global Casino', [$popular], [$this->worldwide]);

        $card = $this->countryCard('germany');

        $this->assertSame(3, $card);
        $this->assertSame($card, $this->countryListingTotal('germany'), 'The card must equal /countries/germany.');
        $this->assertSame($card, $this->chips('germany')['most-popular'], 'The card must equal the chip that holds every one of them.');
        $this->assertChipsMatchListings('germany');
    }

    public function test_the_worldwide_filter_shows_only_worldwide_casinos(): void
    {
        $popular = Category::factory()->create(['name' => 'Most Popular', 'slug' => 'most-popular']);
        $germany = $this->country('Germany', 'germany');

        $this->casino('German Casino', [$popular], [$germany]);
        $this->casino('Global Casino', [$popular], [$this->worldwide]);

        $this->assertSame(1, $this->chips(Country::WORLDWIDE_SLUG)['most-popular']);
        $this->assertChipsMatchListings(Country::WORLDWIDE_SLUG);
    }

    // ── the filter cannot widen ──────────────────────────────────────────────

    public function test_an_unknown_country_matches_nothing_rather_than_everything(): void
    {
        $popular = Category::factory()->create(['name' => 'Most Popular', 'slug' => 'most-popular']);
        $this->casino('Global Casino', [$popular], [$this->worldwide]);

        $this->assertSame([], $this->chips('atlantis'));
        $this->assertSame(0, $this->listingTotal('most-popular', 'atlantis'));
    }

    public function test_a_deactivated_country_matches_nothing(): void
    {
        $popular = Category::factory()->create(['name' => 'Most Popular', 'slug' => 'most-popular']);
        $germany = $this->country('Germany', 'germany');
        $this->casino('German Casino', [$popular], [$germany]);

        $germany->update(['active' => false]);

        $this->assertSame([], $this->chips('germany'));
        $this->assertSame(0, $this->listingTotal('most-popular', 'germany'));
    }

    // ── the country dropdown ON a category page ──────────────────────────────

    /**
     * The mirror of the chips: inside a category, a country card must count
     * that category's casinos, because that is what choosing it will show.
     */
    public function test_a_country_card_can_be_scoped_to_one_category(): void
    {
        $popular = Category::factory()->create(['name' => 'Most Popular', 'slug' => 'most-popular']);
        $spins = Category::factory()->create(['name' => 'Free Spins', 'slug' => 'free-spins']);
        $germany = $this->country('Germany', 'germany');

        $this->casino('In Both', [$popular, $spins], [$germany]);
        $this->casino('Popular Only', [$popular], [$germany]);
        $this->casino('Popular Only Too', [$popular], [$germany]);

        $this->assertSame(3, $this->countryCard('germany'), 'Unscoped, the card counts the whole site.');
        $this->assertSame(1, $this->countryCard('germany', 'free-spins'));
        $this->assertSame(3, $this->countryCard('germany', 'most-popular'));
    }

    public function test_a_category_scoped_country_card_equals_what_choosing_it_shows(): void
    {
        $spins = Category::factory()->create(['name' => 'Free Spins', 'slug' => 'free-spins']);
        $germany = $this->country('Germany', 'germany');

        $this->casino('German Spins', [$spins], [$germany]);
        $this->casino('Global Spins', [$spins], [$this->worldwide]);

        $card = $this->countryCard('germany', 'free-spins');

        $this->assertSame(2, $card, 'The worldwide casino counts here too — the listing will show it.');
        $this->assertSame($card, $this->listingTotal('free-spins', 'germany'));
    }

    public function test_a_country_with_nothing_in_the_category_reports_zero(): void
    {
        $popular = Category::factory()->create(['name' => 'Most Popular', 'slug' => 'most-popular']);
        $spins = Category::factory()->create(['name' => 'Free Spins', 'slug' => 'free-spins']);
        $germany = $this->country('Germany', 'germany');

        $this->casino('Popular Only', [$popular], [$germany]);

        // Zero rather than a number the Free Spins listing cannot produce: the
        // front end drops a zero-count country from the dropdown, so the option
        // never appears rather than appearing and leading nowhere.
        $this->assertSame(0, $this->countryCard('germany', 'free-spins'));
        $this->assertSame(0, $this->listingTotal('free-spins', 'germany'));
    }

    public function test_an_unknown_category_scope_counts_nothing(): void
    {
        $popular = Category::factory()->create(['name' => 'Most Popular', 'slug' => 'most-popular']);
        $germany = $this->country('Germany', 'germany');
        $this->casino('German Casino', [$popular], [$germany]);

        $this->assertSame(0, $this->countryCard('germany', 'no-such-category'));
    }

    public function test_the_unscoped_grid_is_unchanged_by_the_new_parameter(): void
    {
        $popular = Category::factory()->create(['name' => 'Most Popular', 'slug' => 'most-popular']);
        $germany = $this->country('Germany', 'germany');
        $this->casino('In A Category', [$popular], [$germany]);
        // A casino in NO category still counts site-wide: /countries/germany
        // lists it, and the card must agree with that page.
        $this->casino('Uncategorised', [], [$germany]);

        $this->assertSame(2, $this->countryCard('germany'));
        $this->assertSame(2, $this->countryListingTotal('germany'));
    }
}
