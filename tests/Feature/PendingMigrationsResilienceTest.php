<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\CmsPage;
use App\Models\User;
use App\Support\SchemaGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Code deployed BEFORE its migrations must degrade the feature, not the page.
 *
 * Regression: the byline code selected `casino_site.author_id` while the
 * migration adding it was still pending, and every casino page on every site
 * answered 404. These tests remove the newer schema and check the pages and
 * the admin saves still work without it.
 */
class PendingMigrationsResilienceTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake();

        Schema::table('casino_site', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('author_id');
            $table->dropConstrainedForeignId('reviewed_by_id');
            $table->dropColumn('last_reviewed_at');
        });
        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('author_id');
            $table->dropConstrainedForeignId('reviewed_by_id');
            $table->dropColumn(['last_reviewed_at', 'draft_title', 'draft_content', 'draft_meta_title', 'draft_meta_description', 'draft_updated_at']);
        });
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('noindex'));
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('author_id');
            $table->dropConstrainedForeignId('reviewed_by_id');
            $table->dropColumn('last_reviewed_at');
        });
        Schema::drop('authors');

        SchemaGuard::flush();
    }

    protected function tearDown(): void
    {
        SchemaGuard::flush();
        parent::tearDown();
    }

    public function test_casino_page_and_sitemap_render_without_the_new_schema(): void
    {
        [$site, $key] = $this->siteWithKey();
        $casino = Casino::factory()->create(['active' => true]);
        $casino->sites()->attach($site->id, ['affiliate_url' => 'https://x.test/go', 'active' => true]);

        $this->getJson($this->publicBase($site) . '/casinos/' . $casino->slug, $this->siteHeaders($key))
            ->assertOk()
            ->assertJsonPath('data.slug', $casino->slug)
            ->assertJsonMissingPath('data.byline');

        $this->getJson($this->publicBase($site) . '/sitemap', $this->siteHeaders($key))
            ->assertOk()
            ->assertJsonPath('data.authors', []);

        $this->getJson($this->publicBase($site) . '/authors/anyone', $this->siteHeaders($key))->assertNotFound();
    }

    public function test_admin_saves_keep_the_fields_that_exist(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $this->actingAs($user, 'sanctum');
        $page = CmsPage::factory()->create(['title' => 'Old']);

        $this->putJson("/api/v1/admin/pages/{$page->id}", ['title' => 'New', 'draft_title' => 'Ignored for now'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New');
    }
}
