<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BonusCategory;
use App\Models\Casino;
use App\Models\Site;
use App\Models\SpecialOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The Bonus area: categories that drive both the menu and the home page.
 *
 * The assertions that matter most are about the two surfaces AGREEING. One
 * payload feeds the dropdown and the sections, so the tests that earn their keep
 * are the ones proving an empty or hidden category reaches neither.
 */
class BonusCategoryTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->site, $this->key] = $this->siteWithKey(['bonus_enabled' => true]);
    }

    private function category(string $name, int $position = 0, bool $active = true): BonusCategory
    {
        return BonusCategory::create([
            'name' => $name, 'position' => $position, 'active' => $active,
        ]);
    }

    /** An offer visible on this site, filed under a category. */
    private function offer(BonusCategory $category, string $title, bool $active = true): SpecialOffer
    {
        $casino = Casino::factory()->create(['active' => true]);
        $casino->sites()->attach($this->site->id, [
            'active' => true, 'position' => 1, 'affiliate_url' => 'https://example.test/go',
        ]);

        return SpecialOffer::factory()->create([
            'casino_id'         => $casino->id,
            'bonus_category_id' => $category->id,
            'title'             => $title,
            'active'            => $active,
        ]);
    }

    /** @return array<string, list<string>> category name => offer titles */
    private function area(): array
    {
        $data = $this->getJson($this->publicBase($this->site) . '/bonus', $this->siteHeaders($this->key))
            ->assertOk()->json('data');

        $out = [];
        foreach ($data as $c) {
            $out[$c['name']] = array_column($c['offers'], 'title');
        }

        return $out;
    }

    // ── the public payload ───────────────────────────────────────────────────

    public function test_it_returns_categories_with_their_offers(): void
    {
        $special = $this->category('Special Offers', 0);
        $this->offer($special, 'Welcome package');

        $this->assertSame(['Special Offers' => ['Welcome package']], $this->area());
    }

    public function test_categories_come_back_in_position_order(): void
    {
        $b = $this->category('No Deposit', 20);
        $a = $this->category('Special Offers', 10);
        $this->offer($a, 'A');
        $this->offer($b, 'B');

        $this->assertSame(['Special Offers', 'No Deposit'], array_keys($this->area()));
    }

    /**
     * The rule that keeps menu and page honest: no empty section, and therefore
     * no menu entry leading to one.
     */
    public function test_a_category_with_no_visible_offers_is_omitted_entirely(): void
    {
        $shown = $this->category('Special Offers', 10);
        $this->offer($shown, 'Visible');

        $empty = $this->category('No Deposit', 20);           // nothing filed
        $hidden = $this->category('Free Spins', 30);
        $this->offer($hidden, 'Switched off', active: false); // filed but inactive

        $this->assertSame(['Special Offers'], array_keys($this->area()));
        $this->assertNotNull($empty->id);
        $this->assertNotNull($hidden->id);
    }

    public function test_a_hidden_category_is_omitted_even_with_offers(): void
    {
        $hidden = $this->category('Hidden', 10, active: false);
        $this->offer($hidden, 'Has an offer');

        $this->assertSame([], $this->area());
    }

    /** Offers belonging to another site's casinos must not leak in. */
    public function test_offers_are_scoped_to_this_site(): void
    {
        $category = $this->category('Special Offers');

        $otherSite = Site::factory()->create();
        $foreign = Casino::factory()->create(['active' => true]);
        $foreign->sites()->attach($otherSite->id, [
            'active' => true, 'position' => 1, 'affiliate_url' => 'https://example.test/go',
        ]);
        SpecialOffer::factory()->create([
            'casino_id' => $foreign->id, 'bonus_category_id' => $category->id,
            'title' => 'Other site offer', 'active' => true,
        ]);

        $this->assertSame([], $this->area());
    }

    public function test_the_area_404s_when_the_site_has_bonus_switched_off(): void
    {
        $this->site->update(['bonus_enabled' => false]);
        $c = $this->category('Special Offers');
        $this->offer($c, 'Anything');

        $this->getJson($this->publicBase($this->site) . '/bonus', $this->siteHeaders($this->key))
            ->assertNotFound();
    }

    public function test_offers_per_section_are_capped(): void
    {
        $category = $this->category('Special Offers');
        foreach (range(1, 11) as $i) {
            $this->offer($category, "Offer {$i}");
        }

        // Two rows of four compact cards on the home page.
        $this->assertCount(8, $this->area()['Special Offers']);
    }

    /**
     * The FULL listing drops empty categories too.
     *
     * Every other test here exercises the capped variant, which is what the menu
     * and the home page read. `?limit=0` is a different cache key serving
     * /special-offers, and an untested variant is exactly where this rule would
     * quietly stop applying.
     */
    public function test_the_uncapped_listing_also_omits_empty_categories(): void
    {
        $shown = $this->category('Special Offers', 10);
        $this->offer($shown, 'Visible');
        $this->category('No Deposit', 20);   // nothing filed

        $data = $this->getJson($this->publicBase($this->site) . '/bonus?limit=0', $this->siteHeaders($this->key))
            ->assertOk()->json('data');

        $this->assertSame(['Special Offers'], array_column($data, 'name'));
    }

    /**
     * An offer whose casino is detached from this site does not keep its
     * category alive.
     *
     * The category would otherwise appear in the menu and lead to an empty
     * section — the exact symptom this rule exists to prevent, arriving through
     * the attachment rather than through the offer.
     */
    public function test_a_category_whose_only_offer_has_a_detached_casino_is_omitted(): void
    {
        $category = $this->category('Orphaned', 10);

        $casino = Casino::factory()->create(['active' => true]);
        // Attached, but the attachment itself is switched off for this site.
        $casino->sites()->attach($this->site->id, [
            'active' => false, 'position' => 1, 'affiliate_url' => 'https://example.test/go',
        ]);
        SpecialOffer::factory()->create([
            'casino_id' => $casino->id, 'bonus_category_id' => $category->id,
            'title' => 'Hidden by attachment', 'active' => true,
        ]);

        $this->assertSame([], $this->area());
    }

    /** Nor does an offer whose casino has been switched off entirely. */
    public function test_a_category_whose_only_offer_has_an_inactive_casino_is_omitted(): void
    {
        $category = $this->category('Inactive casino', 10);

        $casino = Casino::factory()->create(['active' => false]);
        $casino->sites()->attach($this->site->id, [
            'active' => true, 'position' => 1, 'affiliate_url' => 'https://example.test/go',
        ]);
        SpecialOffer::factory()->create([
            'casino_id' => $casino->id, 'bonus_category_id' => $category->id,
            'title' => 'Dead casino', 'active' => true,
        ]);

        $this->assertSame([], $this->area());
    }

    /**
     * An expired offer does not hold a category open either.
     *
     * `claimable()` filters it out of the section, so the category is left with
     * nothing — and a heading whose every offer has lapsed is the worst kind of
     * empty, because it looks maintained.
     */
    public function test_a_category_whose_only_offer_has_expired_is_omitted(): void
    {
        $category = $this->category('Lapsed', 10);

        $casino = Casino::factory()->create(['active' => true]);
        $casino->sites()->attach($this->site->id, [
            'active' => true, 'position' => 1, 'affiliate_url' => 'https://example.test/go',
        ]);
        SpecialOffer::factory()->create([
            'casino_id' => $casino->id, 'bonus_category_id' => $category->id,
            'title' => 'Ended yesterday', 'active' => true,
            'expires_at' => now()->subDay()->toDateString(),
        ]);

        $this->assertSame([], $this->area());
    }

    // ── the admin CRUD ───────────────────────────────────────────────────────

    public function test_admin_can_add_edit_reorder_hide_and_delete(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/v1/admin/bonus-categories', [
            'name' => 'No Deposit', 'description' => 'No money down.', 'position' => 5,
        ])->assertCreated()->json('data.id');

        $this->assertSame('no-deposit', BonusCategory::findOrFail($id)->slug);

        $this->putJson("/api/v1/admin/bonus-categories/{$id}", ['name' => 'No Deposit Bonuses', 'position' => 2])
            ->assertOk()
            ->assertJsonPath('data.name', 'No Deposit Bonuses')
            ->assertJsonPath('data.position', 2);

        // The slug is in the public URL and must NOT follow a rename.
        $this->assertSame('no-deposit', BonusCategory::findOrFail($id)->slug);

        $this->putJson("/api/v1/admin/bonus-categories/{$id}", ['active' => false])
            ->assertOk()->assertJsonPath('data.active', false);

        $this->deleteJson("/api/v1/admin/bonus-categories/{$id}")->assertOk();
        $this->assertNull(BonusCategory::find($id));
    }

    /** Deleting a heading must not delete the offers filed under it. */
    public function test_deleting_a_category_orphans_its_offers_rather_than_destroying_them(): void
    {
        $category = $this->category('Special Offers');
        $offer = $this->offer($category, 'Survives');
        $this->actingAsAdmin();

        $this->deleteJson("/api/v1/admin/bonus-categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('orphaned', 1);

        $offer->refresh();
        $this->assertNotNull($offer->id, 'The offer must survive its category.');
        $this->assertNull($offer->bonus_category_id);
    }

    public function test_the_admin_list_includes_hidden_categories_and_their_counts(): void
    {
        $category = $this->category('Hidden', 10, active: false);
        $this->offer($category, 'One');
        $this->actingAsAdmin();

        $row = collect($this->getJson('/api/v1/admin/bonus-categories')->assertOk()->json('data'))
            ->firstWhere('name', 'Hidden');

        $this->assertNotNull($row);
        $this->assertFalse($row['active']);
        $this->assertSame(1, $row['offers_count']);
    }
}
