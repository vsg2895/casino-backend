<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\Category;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class AdminCasinoTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    public function test_admin_casino_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/casinos')->assertUnauthorized();
        $this->postJson('/api/v1/admin/casinos', [])->assertUnauthorized();
    }

    public function test_admin_can_create_a_casino_with_categories(): void
    {
        $this->actingAsAdmin();
        $categories = Category::factory()->count(2)->create();

        $response = $this->postJson('/api/v1/admin/casinos', [
            'name'         => 'New Casino',
            'bonuses'      => '100% up to $500',
            'rating'       => 4,
            'active'       => true,
            'category_ids' => $categories->pluck('id')->all(),
        ])->assertCreated()->assertJsonPath('data.name', 'New Casino');

        $casino = Casino::firstWhere('name', 'New Casino');
        $this->assertNotNull($casino->slug, 'slug auto-generated');
        $this->assertEqualsCanonicalizing($categories->pluck('id')->all(), $casino->categories->pluck('id')->all());
    }

    public function test_create_validates_input(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/casinos', ['name' => '', 'rating' => 99])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'rating']);
    }

    public function test_admin_can_update_a_casino(): void
    {
        $this->actingAsAdmin();
        $casino = Casino::factory()->create(['name' => 'Old', 'bonuses' => 'old']);

        $this->putJson("/api/v1/admin/casinos/{$casino->id}", ['bonuses' => 'NEW BONUS'])
            ->assertOk()
            ->assertJsonPath('data.bonuses', 'NEW BONUS');

        $this->assertSame('Old', $casino->fresh()->name, 'slug/name stable on update');
    }

    public function test_admin_can_soft_delete_a_casino(): void
    {
        $this->actingAsAdmin();
        $casino = Casino::factory()->create();

        $this->deleteJson("/api/v1/admin/casinos/{$casino->id}")->assertNoContent();
        $this->assertSoftDeleted('casinos', ['id' => $casino->id]);
    }

    // ── Attachment sync ───────────────────────────────────────────────────

    public function test_sync_replaces_the_set_of_attached_sites(): void
    {
        $this->actingAsAdmin();
        $casino = Casino::factory()->create();
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();

        // attach to A + B
        $this->postJson("/api/v1/admin/casinos/{$casino->id}/sites/sync", [
            'sites' => [
                ['site_id' => $siteA->id, 'affiliate_url' => 'https://a.test/go', 'position' => 1, 'featured' => true],
                ['site_id' => $siteB->id, 'affiliate_url' => 'https://b.test/go', 'position' => 2, 'featured' => false],
            ],
        ])->assertOk();

        $this->assertEqualsCanonicalizing([$siteA->id, $siteB->id], $casino->sites()->pluck('sites.id')->all());

        // re-sync to A only -> B detached, A's affiliate updated
        $this->postJson("/api/v1/admin/casinos/{$casino->id}/sites/sync", [
            'sites' => [
                ['site_id' => $siteA->id, 'affiliate_url' => 'https://a.test/updated', 'position' => 0, 'featured' => false],
            ],
        ])->assertOk();

        $this->assertSame([$siteA->id], $casino->sites()->pluck('sites.id')->all());
        $this->assertSame('https://a.test/updated', $casino->sites()->first()->pivot->affiliate_url);
    }

    public function test_sync_validates_affiliate_url(): void
    {
        $this->actingAsAdmin();
        $casino = Casino::factory()->create();
        $site = Site::factory()->create();

        $this->postJson("/api/v1/admin/casinos/{$casino->id}/sites/sync", [
            'sites' => [['site_id' => $site->id, 'affiliate_url' => 'not-a-url']],
        ])->assertStatus(422)->assertJsonValidationErrors(['sites.0.affiliate_url']);
    }
}
