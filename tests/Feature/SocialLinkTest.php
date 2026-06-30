<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RevalidateNextJsSites;
use App\Models\SocialLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class SocialLinkTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ── Admin CRUD ────────────────────────────────────────────────────────

    public function test_admin_can_create_update_and_delete_a_social_link(): void
    {
        Bus::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $id = $this->postJson('/api/v1/admin/social-links', [
            'site_id'    => $site->id,
            'platform'   => 'instagram',
            'label'      => '@crystal',
            'url'        => 'https://instagram.com/crystal',
            'sort_order' => 1,
            'active'     => true,
        ])->assertCreated()
            ->assertJsonPath('data.platform', 'instagram')
            ->assertJsonPath('data.site_id', $site->id)
            ->json('data.id');

        $this->patchJson("/api/v1/admin/social-links/{$id}", ['url' => 'https://instagram.com/updated'])
            ->assertOk()->assertJsonPath('data.url', 'https://instagram.com/updated');

        $this->deleteJson("/api/v1/admin/social-links/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('social_links', ['id' => $id]);

        // Each write busts the site's cache + queues a revalidation ping.
        Bus::assertDispatched(
            RevalidateNextJsSites::class,
            fn (RevalidateNextJsSites $job): bool => $job->siteIds === [$site->id] && in_array('social-links', $job->tags, true),
        );
    }

    public function test_create_requires_valid_platform_and_url(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson('/api/v1/admin/social-links', [
            'site_id'  => $site->id,
            'platform' => 'myspace',          // not in the supported set
            'url'      => 'not-a-url',
        ])->assertStatus(422)->assertJsonValidationErrors(['platform', 'url']);
    }

    public function test_admin_index_can_filter_by_site(): void
    {
        $this->actingAsAdmin();
        [$siteA] = $this->siteWithKey();
        [$siteB] = $this->siteWithKey();
        SocialLink::factory()->count(2)->create(['site_id' => $siteA->id]);
        SocialLink::factory()->create(['site_id' => $siteB->id]);

        $this->getJson("/api/v1/admin/social-links?site_id={$siteA->id}")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_endpoints_require_authentication(): void
    {
        [$site] = $this->siteWithKey();
        $this->postJson('/api/v1/admin/social-links', ['site_id' => $site->id, 'platform' => 'facebook', 'url' => 'https://facebook.com/x'])
            ->assertUnauthorized();
    }

    // ── Public (site-scoped, footer) ──────────────────────────────────────

    public function test_public_endpoint_returns_only_active_links_for_the_site_in_order(): void
    {
        [$site, $key] = $this->siteWithKey();
        [$other] = $this->siteWithKey();

        SocialLink::factory()->create(['site_id' => $site->id, 'platform' => 'twitter', 'sort_order' => 2, 'active' => true]);
        SocialLink::factory()->create(['site_id' => $site->id, 'platform' => 'facebook', 'sort_order' => 1, 'active' => true]);
        SocialLink::factory()->inactive()->create(['site_id' => $site->id, 'platform' => 'youtube']);
        SocialLink::factory()->create(['site_id' => $other->id, 'platform' => 'instagram', 'active' => true]);

        $res = $this->getJson($this->publicBase($site) . '/social-links', $this->siteHeaders($key))
            ->assertOk()
            ->assertJsonCount(2, 'data');           // active links of THIS site only

        // ordered by sort_order: facebook (1) before twitter (2)
        $this->assertSame('facebook', $res->json('data.0.platform'));
        $this->assertSame('twitter', $res->json('data.1.platform'));
    }

    public function test_public_endpoint_requires_a_valid_site_key(): void
    {
        [$site] = $this->siteWithKey();
        $this->getJson($this->publicBase($site) . '/social-links', ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }
}
