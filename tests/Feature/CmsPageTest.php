<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CmsPage;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class CmsPageTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function superAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    // ── Public: GET /api/v1/public/sites/{slug}/pages/{pageSlug} ───────────

    public function test_public_can_view_a_published_page(): void
    {
        [$site, $key] = $this->siteWithKey();
        CmsPage::factory()->published()->create([
            'site_id' => $site->id, 'slug' => 'privacy-policy', 'title' => 'Privacy Policy',
        ]);

        $this->getJson($this->publicBase($site) . '/pages/privacy-policy', $this->siteHeaders($key))
            ->assertOk()
            ->assertJsonPath('data.slug', 'privacy-policy')
            ->assertJsonPath('data.title', 'Privacy Policy')
            ->assertJsonStructure(['data' => ['slug', 'title', 'content', 'meta_title', 'meta_description', 'updated_at']])
            ->assertJsonMissingPath('data.status');
    }

    public function test_public_cannot_view_a_draft_page(): void
    {
        [$site, $key] = $this->siteWithKey();
        CmsPage::factory()->draft()->create(['site_id' => $site->id, 'slug' => 'terms']);

        $this->getJson($this->publicBase($site) . '/pages/terms', $this->siteHeaders($key))->assertNotFound();
    }

    public function test_public_gets_404_for_unknown_slug(): void
    {
        [$site, $key] = $this->siteWithKey();

        $this->getJson($this->publicBase($site) . '/pages/does-not-exist', $this->siteHeaders($key))->assertNotFound();
    }

    public function test_a_sites_published_page_is_not_visible_through_another_site(): void
    {
        [$siteA] = $this->siteWithKey();
        [$siteB, $keyB] = $this->siteWithKey();
        CmsPage::factory()->published()->create(['site_id' => $siteA->id, 'slug' => 'privacy-policy']);

        // Site B's key must not surface Site A's page.
        $this->getJson($this->publicBase($siteB) . '/pages/privacy-policy', $this->siteHeaders($keyB))->assertNotFound();
    }

    // ── Admin auth/authorization ──────────────────────────────────────────

    public function test_admin_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/pages')->assertUnauthorized();
    }

    public function test_admin_endpoints_forbidden_for_non_super_admin(): void
    {
        $user = User::factory()->create(); // no role

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/pages')
            ->assertForbidden();
    }

    // ── Admin CRUD ────────────────────────────────────────────────────────

    public function test_super_admin_can_list_pages(): void
    {
        CmsPage::factory()->count(3)->create();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson('/api/v1/admin/pages')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'site_id', 'slug', 'title', 'status']]]);
    }

    public function test_super_admin_can_filter_pages_by_site(): void
    {
        [$siteA] = $this->siteWithKey();
        [$siteB] = $this->siteWithKey();
        CmsPage::factory()->create(['site_id' => $siteA->id]);
        CmsPage::factory()->create(['site_id' => $siteB->id]);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson('/api/v1/admin/pages?site_id=' . $siteA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.site_id', $siteA->id);
    }

    public function test_super_admin_can_create_a_page(): void
    {
        [$site] = $this->siteWithKey();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/v1/admin/pages', [
                'site_id' => $site->id,
                'slug'    => 'about',
                'title'   => 'About Us',
                'content' => '<p>Hello</p>',
                'status'  => 'published',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'about')
            ->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('cms_pages', ['site_id' => $site->id, 'slug' => 'about', 'status' => 'published']);
    }

    public function test_create_requires_a_site(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/v1/admin/pages', ['slug' => 'about', 'title' => 'About'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_id']);
    }

    public function test_create_defaults_to_draft_when_status_omitted(): void
    {
        [$site] = $this->siteWithKey();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/v1/admin/pages', ['site_id' => $site->id, 'slug' => 'contact', 'title' => 'Contact'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_create_validates_input(): void
    {
        [$site] = $this->siteWithKey();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/v1/admin/pages', ['site_id' => $site->id, 'slug' => 'Invalid Slug!', 'title' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug', 'title']);
    }

    public function test_create_rejects_duplicate_slug_within_same_site(): void
    {
        [$site] = $this->siteWithKey();
        CmsPage::factory()->create(['site_id' => $site->id, 'slug' => 'terms']);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/v1/admin/pages', ['site_id' => $site->id, 'slug' => 'terms', 'title' => 'Terms'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_same_slug_is_allowed_on_different_sites(): void
    {
        [$siteA] = $this->siteWithKey();
        [$siteB] = $this->siteWithKey();
        CmsPage::factory()->create(['site_id' => $siteA->id, 'slug' => 'terms']);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/v1/admin/pages', ['site_id' => $siteB->id, 'slug' => 'terms', 'title' => 'Terms'])
            ->assertCreated();
    }

    public function test_super_admin_can_update_and_publish_a_page(): void
    {
        [$site, $key] = $this->siteWithKey();
        $page = CmsPage::factory()->draft()->create(['site_id' => $site->id, 'slug' => 'cookie-policy', 'title' => 'Old']);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->putJson("/api/v1/admin/pages/{$page->id}", ['title' => 'Cookie Policy', 'status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Cookie Policy')
            ->assertJsonPath('data.status', 'published');

        $this->getJson($this->publicBase($site) . '/pages/cookie-policy', $this->siteHeaders($key))
            ->assertOk()->assertJsonPath('data.title', 'Cookie Policy');
    }

    public function test_super_admin_can_delete_a_page(): void
    {
        $page = CmsPage::factory()->create();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->deleteJson("/api/v1/admin/pages/{$page->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('cms_pages', ['id' => $page->id]);
    }

    public function test_registering_a_site_seeds_the_standard_pages(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/v1/admin/sites', [
                'name'   => 'New Brand',
                'slug'   => 'new-brand',
                'domain' => 'newbrand.test',
            ])
            ->assertCreated();

        $site = Site::where('slug', 'new-brand')->firstOrFail();
        $this->assertDatabaseHas('cms_pages', ['site_id' => $site->id, 'slug' => 'privacy-policy', 'status' => 'published']);
        $this->assertDatabaseHas('cms_pages', ['site_id' => $site->id, 'slug' => 'aml-policy']);
        $this->assertSame(11, CmsPage::where('site_id', $site->id)->count());
    }
}
