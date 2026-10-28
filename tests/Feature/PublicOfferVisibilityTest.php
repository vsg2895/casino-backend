<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\Category;
use App\Models\Site;
use App\Models\SpecialOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Which offers the public surfaces present as claimable.
 *
 * Every listing must apply the same gates as the casino page and /bonus: the
 * offer switched on, not expired, and owned by a casino that is active both
 * globally and on this site. The single-offer page is deliberately NOT gated
 * (an admin can open a hidden offer's link), and that is pinned here too.
 */
class PublicOfferVisibilityTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function attachedCasino(Site $site, array $attrs = []): Casino
    {
        $casino = Casino::factory()->create($attrs);
        $casino->sites()->attach($site->id, ['affiliate_url' => 'https://x.test/go', 'active' => true]);

        return $casino;
    }

    // ── /special-offers ───────────────────────────────────────────────────

    public function test_listing_omits_offers_of_a_globally_inactive_casino(): void
    {
        [$site, $key] = $this->siteWithKey();
        $live = SpecialOffer::factory()->for($this->attachedCasino($site))->create();
        $orphaned = SpecialOffer::factory()->for($this->attachedCasino($site, ['active' => false]))->create();

        $slugs = collect($this->getJson($this->publicBase($site).'/special-offers', $this->siteHeaders($key))
            ->assertOk()
            ->json('data'))->pluck('slug');

        $this->assertTrue($slugs->contains($live->slug));
        $this->assertFalse($slugs->contains($orphaned->slug));
    }

    public function test_category_filtered_listing_also_omits_a_globally_inactive_casino(): void
    {
        [$site, $key] = $this->siteWithKey();
        $slots = Category::factory()->create(['slug' => 'slots']);
        $inactive = $this->attachedCasino($site, ['active' => false]);
        $inactive->categories()->attach($slots->id);
        SpecialOffer::factory()->for($inactive)->create();

        $this->getJson($this->publicBase($site).'/special-offers?category=slots', $this->siteHeaders($key))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_single_offer_page_still_opens_for_a_globally_inactive_casino(): void
    {
        [$site, $key] = $this->siteWithKey();
        $offer = SpecialOffer::factory()->for($this->attachedCasino($site, ['active' => false]))->create();

        $this->getJson($this->publicBase($site).'/special-offers/'.$offer->slug, $this->siteHeaders($key))
            ->assertOk()
            ->assertJsonPath('data.slug', $offer->slug);
    }

    // ── is_indexable ──────────────────────────────────────────────────────

    public function test_a_short_offer_without_terms_is_still_indexable(): void
    {
        [$site, $key] = $this->siteWithKey();
        $offer = SpecialOffer::factory()->for($this->attachedCasino($site))->create([
            'description'          => 'Ten free spins on sign-up.',
            'wagering_requirement' => null,
            'min_deposit'          => null,
            'max_cashout'          => null,
        ]);

        $this->getJson($this->publicBase($site).'/special-offers/'.$offer->slug, $this->siteHeaders($key))
            ->assertOk()
            ->assertJsonPath('data.is_indexable', true);
    }

    public function test_an_admin_noindex_keeps_an_offer_out_of_the_index(): void
    {
        [$site, $key] = $this->siteWithKey();
        $offer = SpecialOffer::factory()->for($this->attachedCasino($site))->create(['noindex' => true]);

        $this->getJson($this->publicBase($site).'/special-offers/'.$offer->slug, $this->siteHeaders($key))
            ->assertOk()
            ->assertJsonPath('data.is_indexable', false);
    }

    // ── /categories/{slug} featured offer ─────────────────────────────────

    private function categoryCasinoWithFeatured(Site $site, array $offerAttrs): Casino
    {
        $category = Category::query()->firstOrCreate(['slug' => 'slots'], ['name' => 'Slots']);
        $casino = $this->attachedCasino($site);
        $casino->categories()->attach($category->id);
        $offer = SpecialOffer::factory()->for($casino)->create($offerAttrs);
        $casino->forceFill(['featured_special_offer_id' => $offer->id])->save();

        return $casino;
    }

    private function featuredOnCategoryPage(Site $site, string $key): mixed
    {
        return $this->getJson($this->publicBase($site).'/categories/slots', $this->siteHeaders($key))
            ->assertOk()
            ->json('data.casinos.0.featured_special_offer');
    }

    public function test_category_page_keeps_a_claimable_featured_offer(): void
    {
        [$site, $key] = $this->siteWithKey();
        $this->categoryCasinoWithFeatured($site, ['expires_at' => now()->addWeek()->toDateString()]);

        $this->assertNotNull($this->featuredOnCategoryPage($site, $key));
    }

    public function test_category_page_keeps_a_featured_offer_expiring_today(): void
    {
        [$site, $key] = $this->siteWithKey();
        $this->categoryCasinoWithFeatured($site, ['expires_at' => now()->toDateString()]);

        $this->assertNotNull($this->featuredOnCategoryPage($site, $key));
    }

    public function test_category_page_drops_an_expired_featured_offer(): void
    {
        [$site, $key] = $this->siteWithKey();
        $this->categoryCasinoWithFeatured($site, ['expires_at' => now()->subDay()->toDateString()]);

        $this->assertNull($this->featuredOnCategoryPage($site, $key));
    }

    public function test_category_page_drops_a_switched_off_featured_offer(): void
    {
        [$site, $key] = $this->siteWithKey();
        $this->categoryCasinoWithFeatured($site, ['active' => false]);

        $this->assertNull($this->featuredOnCategoryPage($site, $key));
    }
}
