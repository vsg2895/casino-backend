<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * SEO audit H6: a site can carry its own review text for a shared casino
 * (casino_site.description); without one it keeps the shared text.
 */
class CasinoSiteDescriptionTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function attachedCasino(Site ...$sites): Casino
    {
        $casino = Casino::factory()->create(['active' => true, 'description' => '<p>Shared review.</p>']);

        foreach ($sites as $site) {
            $casino->sites()->attach($site->id, ['affiliate_url' => 'https://x.test/go', 'active' => true]);
        }

        return $casino;
    }

    private function putDescription(Casino $casino, Site $site, ?string $text): TestResponse
    {
        return $this->putJson("/api/v1/admin/casinos/{$casino->id}/sites/{$site->id}/description", ['description' => $text]);
    }

    public function test_a_site_shows_its_own_text_and_the_others_keep_the_shared_one(): void
    {
        [$own, $ownKey] = $this->siteWithKey();
        [$other, $otherKey] = $this->siteWithKey();
        $casino = $this->attachedCasino($own, $other);

        $this->actingAsAdmin();
        $this->putDescription($casino, $own, '<p>Our own take.</p>')
            ->assertOk()
            ->assertJsonPath('data.description', '<p>Our own take.</p>');

        $this->getJson($this->publicBase($own) . '/casinos/' . $casino->slug, $this->siteHeaders($ownKey))
            ->assertOk()->assertJsonPath('data.description', '<p>Our own take.</p>');
        $this->getJson($this->publicBase($other) . '/casinos/' . $casino->slug, $this->siteHeaders($otherKey))
            ->assertOk()->assertJsonPath('data.description', '<p>Shared review.</p>');
    }

    public function test_an_empty_or_copied_text_falls_back_to_the_shared_one(): void
    {
        [$site] = $this->siteWithKey();
        $casino = $this->attachedCasino($site);
        $this->actingAsAdmin();

        $this->putDescription($casino, $site, '<p>Own.</p>')->assertOk();
        $this->putDescription($casino, $site, '<p><br></p>')->assertOk()->assertJsonPath('data.description', null);

        $this->putDescription($casino, $site, '<p>Own.</p>')->assertOk();
        $this->putDescription($casino, $site, '<p>Shared review.</p>')->assertOk()->assertJsonPath('data.description', null);
    }

    public function test_saving_the_attachment_table_keeps_the_text(): void
    {
        [$site] = $this->siteWithKey();
        $casino = $this->attachedCasino($site);
        $this->actingAsAdmin();
        $this->putDescription($casino, $site, '<p>Own.</p>')->assertOk();

        $this->postJson("/api/v1/admin/casinos/{$casino->id}/sites/sync", ['sites' => [['site_id' => $site->id, 'affiliate_url' => 'https://x.test/new']]])
            ->assertOk()
            ->assertJsonPath('data.0.description', '<p>Own.</p>');
    }

    public function test_a_site_the_casino_is_not_attached_to_is_refused(): void
    {
        [$site] = $this->siteWithKey();
        $casino = $this->attachedCasino();
        $this->actingAsAdmin();

        $this->putDescription($casino, $site, '<p>Own.</p>')->assertNotFound();
    }
}
