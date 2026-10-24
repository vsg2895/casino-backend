<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AffiliateClick;
use App\Models\Casino;
use App\Models\Site;
use App\Models\SpecialOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class AffiliateClickTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function attach(Casino $casino, Site $site, bool $active = true): void
    {
        $casino->sites()->attach($site->id, [
            'affiliate_url' => 'https://example.test/go/'.$casino->slug,
            'position' => 0,
            'featured' => false,
            'active' => $active,
        ]);
    }

    private function click(Site $site, string $key, array $payload): TestResponse
    {
        return $this->postJson($this->publicBase($site).'/affiliate-clicks', $payload, $this->siteHeaders($key));
    }

    public function test_returns_422_when_casino_slug_is_missing(): void
    {
        [$site, $key] = $this->siteWithKey();

        $this->click($site, $key, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['casino_slug' => 'The casino slug field is required.']);

        $this->assertDatabaseCount('affiliate_clicks', 0);
    }

    public function test_counts_repeat_clicks_on_one_daily_row_and_returns_204(): void
    {
        [$site, $key] = $this->siteWithKey();
        $casino = Casino::factory()->create();
        $this->attach($casino, $site);

        $this->click($site, $key, ['casino_slug' => $casino->slug])->assertNoContent();
        $this->click($site, $key, ['casino_slug' => $casino->slug])->assertNoContent();

        $this->assertDatabaseCount('affiliate_clicks', 1);
        $this->assertDatabaseHas('affiliate_clicks', [
            'site_id' => $site->id,
            'casino_id' => $casino->id,
            'special_offer_id' => AffiliateClick::NO_OFFER,
            'count' => 2,
        ]);
    }

    public function test_does_not_count_a_casino_attached_only_to_another_site(): void
    {
        [$site, $key] = $this->siteWithKey(['slug' => 'site-a', 'domain' => 'a.example.test']);
        [$other] = $this->siteWithKey(['slug' => 'site-b', 'domain' => 'b.example.test']);
        $casino = Casino::factory()->create();
        $this->attach($casino, $other);

        $this->click($site, $key, ['casino_slug' => $casino->slug])->assertNoContent();

        $this->assertDatabaseCount('affiliate_clicks', 0);
    }

    public function test_does_not_count_a_casino_whose_attachment_is_inactive(): void
    {
        [$site, $key] = $this->siteWithKey();
        $casino = Casino::factory()->create();
        $this->attach($casino, $site, active: false);

        $this->click($site, $key, ['casino_slug' => $casino->slug])->assertNoContent();

        $this->assertDatabaseCount('affiliate_clicks', 0);
    }

    /**
     * Current behaviour, pinned on purpose: only the ATTACHMENT is checked, not
     * the casino's own `active` flag. A refactor that silently adds that gate
     * would change which clicks are counted.
     */
    public function test_counts_a_globally_inactive_casino_on_an_active_attachment(): void
    {
        [$site, $key] = $this->siteWithKey();
        $casino = Casino::factory()->create(['active' => false]);
        $this->attach($casino, $site);

        $this->click($site, $key, ['casino_slug' => $casino->slug])->assertNoContent();

        $this->assertDatabaseHas('affiliate_clicks', ['casino_id' => $casino->id, 'count' => 1]);
    }

    public function test_records_the_offer_when_its_slug_belongs_to_the_casino(): void
    {
        [$site, $key] = $this->siteWithKey();
        $casino = Casino::factory()->create();
        $this->attach($casino, $site);
        $offer = SpecialOffer::factory()->for($casino)->create();

        $this->click($site, $key, ['casino_slug' => $casino->slug, 'offer_slug' => $offer->slug])->assertNoContent();

        $this->assertDatabaseHas('affiliate_clicks', ['casino_id' => $casino->id, 'special_offer_id' => $offer->id]);
    }

    public function test_falls_back_to_the_casino_link_when_the_offer_belongs_to_another_casino(): void
    {
        [$site, $key] = $this->siteWithKey();
        $casino = Casino::factory()->create();
        $this->attach($casino, $site);
        $foreignOffer = SpecialOffer::factory()->create();

        $this->click($site, $key, ['casino_slug' => $casino->slug, 'offer_slug' => $foreignOffer->slug])->assertNoContent();

        $this->assertDatabaseHas('affiliate_clicks', [
            'casino_id' => $casino->id,
            'special_offer_id' => AffiliateClick::NO_OFFER,
        ]);
    }
}
