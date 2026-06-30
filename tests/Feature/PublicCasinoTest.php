<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class PublicCasinoTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // public reads are cached per-site; isolate each test
    }

    private function attach(Casino $casino, int $siteId, array $pivot = []): void
    {
        $casino->sites()->attach($siteId, [
            'affiliate_url' => 'https://example.test/go/' . $casino->slug,
            'position'      => 0,
            'featured'      => false,
            'active'        => true,
            ...$pivot,
        ]);
    }

    public function test_requires_a_site_key(): void
    {
        [$site] = $this->siteWithKey();

        $this->getJson($this->publicBase($site) . '/casinos')->assertStatus(401);
    }

    public function test_rejects_an_invalid_site_key(): void
    {
        [$site] = $this->siteWithKey();

        $this->getJson($this->publicBase($site) . '/casinos', ['X-Site-Key' => 'wrong'])
            ->assertStatus(403);
    }

    public function test_lists_only_casinos_attached_to_the_requesting_site(): void
    {
        [$siteA, $keyA] = $this->siteWithKey(['slug' => 'site-a', 'domain' => 'a.example.test']);
        [$siteB]        = $this->siteWithKey(['slug' => 'site-b', 'domain' => 'b.example.test']);

        $casinoA = Casino::factory()->create(['name' => 'Casino A']);
        $casinoB = Casino::factory()->create(['name' => 'Casino B']);
        $this->attach($casinoA, $siteA->id, ['affiliate_url' => 'https://a.example.test/go']);
        $this->attach($casinoB, $siteB->id);

        $response = $this->getJson($this->publicBase($siteA) . '/casinos', $this->siteHeaders($keyA))->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($casinoA->id));
        $this->assertFalse($ids->contains($casinoB->id), 'Casino from another site must not leak');
        $this->assertSame('https://a.example.test/go', $response->json('data.0.attachment.affiliate_url'));
    }

    public function test_hides_inactive_pivot_and_inactive_casino(): void
    {
        [$site, $key] = $this->siteWithKey();

        $visible        = Casino::factory()->create();
        $inactivePivot  = Casino::factory()->create();
        $inactiveCasino = Casino::factory()->create(['active' => false]);

        $this->attach($visible, $site->id);
        $this->attach($inactivePivot, $site->id, ['active' => false]);
        $this->attach($inactiveCasino, $site->id);

        $ids = collect($this->getJson($this->publicBase($site) . '/casinos', $this->siteHeaders($key))->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($inactivePivot->id));
        $this->assertFalse($ids->contains($inactiveCasino->id));
    }

    public function test_show_returns_casino_by_slug_with_relations(): void
    {
        [$site, $key] = $this->siteWithKey();
        $casino = Casino::factory()->create(['name' => 'Findable Casino']);
        $this->attach($casino, $site->id);

        $this->getJson($this->publicBase($site) . '/casinos/' . $casino->slug, $this->siteHeaders($key))
            ->assertOk()
            ->assertJsonPath('data.slug', $casino->slug)
            ->assertJsonStructure(['data' => ['id', 'name', 'slug', 'attachment' => ['affiliate_url'], 'categories', 'special_offers']]);
    }

    public function test_show_returns_404_for_unknown_or_unattached_slug(): void
    {
        [$site, $key] = $this->siteWithKey();
        $unattached = Casino::factory()->create();

        $this->getJson($this->publicBase($site) . '/casinos/nope', $this->siteHeaders($key))->assertStatus(404);
        $this->getJson($this->publicBase($site) . '/casinos/' . $unattached->slug, $this->siteHeaders($key))->assertStatus(404);
    }
}
