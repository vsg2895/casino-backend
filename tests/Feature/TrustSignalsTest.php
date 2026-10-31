<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Casino;
use App\Models\CmsPage;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * E-E-A-T signals: legal identity from settings, authors and bylines, and
 * reviewable drafts of the trust pages.
 *
 * Most tests here are about what must NOT happen: a hidden author or one from
 * another site reaching a page, a placeholder reaching a reader, a draft
 * becoming public before someone publishes it.
 */
class TrustSignalsTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake();
    }

    private function superAdmin(): User
    {
        $user = $this->actingAsAdmin();
        $user->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));

        return $user;
    }

    private function attachedCasino(Site $site, array $pivot = []): Casino
    {
        $casino = Casino::factory()->create(['active' => true]);
        $casino->sites()->attach($site->id, ['affiliate_url' => 'https://x.test/go', 'active' => true, ...$pivot]);

        return $casino;
    }

    // ── Legal identity ───────────────────────────────────────────────────────

    public function test_legal_pages_render_identity_from_settings_and_hide_empty_lines(): void
    {
        [$site, $key] = $this->siteWithKey(['legal_entity_name' => 'Ace Media Ltd', 'registered_address' => null]);
        CmsPage::factory()->create([
            'site_id' => $site->id, 'slug' => 'contact', 'status' => 'published',
            'content' => '<h2 data-requires="registered_address">Postal Address</h2><p>Operated by {{legal_entity_name}}. Based at {{registered_address}}.</p>',
        ]);

        $content = $this->getJson($this->publicBase($site) . '/pages/contact', $this->siteHeaders($key))
            ->assertOk()
            ->json('data.content');

        $this->assertSame('<p>Operated by Ace Media Ltd.</p>', $content);
    }

    public function test_editorial_endpoint_publishes_identity_with_nulls_for_empty_fields(): void
    {
        [$site, $key] = $this->siteWithKey(['legal_entity_name' => 'Ace Media Ltd', 'contact_email' => 'hello@ace.test']);

        $this->getJson($this->publicBase($site) . '/editorial', $this->siteHeaders($key))
            ->assertOk()
            ->assertJsonPath('data.legal.legal_entity_name', 'Ace Media Ltd')
            ->assertJsonPath('data.legal.registered_address', null)
            // One inbox covers the specialised ones until they are set.
            ->assertJsonPath('data.legal.support_email', 'hello@ace.test');
    }

    public function test_admin_sees_which_required_identity_fields_are_missing(): void
    {
        $this->actingAsAdmin();
        $site = Site::factory()->create(['legal_entity_name' => 'Ace Media Ltd']);

        $this->putJson("/api/v1/admin/sites/{$site->id}", ['contact_email' => 'hello@ace.test'])
            ->assertOk()
            ->assertJsonPath('data.contact_email', 'hello@ace.test')
            ->assertJsonPath('data.legal_identity_missing', ['registered_address']);
    }

    public function test_changing_identity_flushes_the_cached_editorial_payload(): void
    {
        [$site, $key] = $this->siteWithKey(['legal_entity_name' => 'Old Name Ltd']);
        $this->getJson($this->publicBase($site) . '/editorial', $this->siteHeaders($key))->assertJsonPath('data.legal.legal_entity_name', 'Old Name Ltd');

        $this->actingAsAdmin();
        $this->putJson("/api/v1/admin/sites/{$site->id}", ['legal_entity_name' => 'New Name Ltd'])->assertOk();

        $this->getJson($this->publicBase($site) . '/editorial', $this->siteHeaders($key))->assertJsonPath('data.legal.legal_entity_name', 'New Name Ltd');
    }

    // ── Authors ──────────────────────────────────────────────────────────────

    public function test_admin_crud_is_scoped_to_the_site_in_the_path(): void
    {
        $this->actingAsAdmin();
        $site = Site::factory()->create();
        $other = Site::factory()->create();

        $id = $this->postJson("/api/v1/admin/sites/{$site->id}/authors", [
            'name' => 'Jane Example', 'expertise' => ['Payments'],
            'profile_links' => [['label' => 'LinkedIn', 'url' => 'https://linkedin.com/in/jane']],
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'jane-example')
            // Nothing about a person is public until someone decides it is.
            ->assertJsonPath('data.visible', false)
            ->json('data.id');

        $this->putJson("/api/v1/admin/sites/{$other->id}/authors/{$id}", ['name' => 'Hijack'])->assertNotFound();
        $this->getJson("/api/v1/admin/sites/{$site->id}/authors")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/sites/{$other->id}/authors")->assertJsonCount(0, 'data');
    }

    public function test_public_author_page_404s_when_hidden_or_on_another_site(): void
    {
        [$site, $key] = $this->siteWithKey();
        $hidden = Author::factory()->hidden()->create(['site_id' => $site->id]);
        $foreign = Author::factory()->create();

        $this->getJson($this->publicBase($site) . '/authors/' . $hidden->slug, $this->siteHeaders($key))->assertNotFound();
        $this->getJson($this->publicBase($site) . '/authors/' . $foreign->slug, $this->siteHeaders($key))->assertNotFound();
    }

    public function test_public_author_page_lists_only_work_this_site_publishes(): void
    {
        [$site, $key] = $this->siteWithKey(['news_enabled' => true]);
        $author = Author::factory()->create(['site_id' => $site->id]);
        $reviewed = $this->attachedCasino($site, ['reviewed_by_id' => $author->id]);
        $detached = Casino::factory()->create(['active' => true]);
        $detached->sites()->attach($site->id, ['affiliate_url' => 'https://x.test', 'active' => false, 'author_id' => $author->id]);
        $live = Article::create(['site_id' => $site->id, 'type' => 'news', 'title' => 'Live post', 'body' => 'x', 'active' => true, 'published_at' => Carbon::now()->subDay(), 'author_id' => $author->id]);
        Article::create(['site_id' => $site->id, 'type' => 'news', 'title' => 'Draft post', 'body' => 'x', 'active' => false, 'author_id' => $author->id]);

        $this->getJson($this->publicBase($site) . '/authors/' . $author->slug, $this->siteHeaders($key))
            ->assertOk()
            ->assertJsonPath('data.author.name', $author->name)
            ->assertJsonPath('data.casinos.0.slug', $reviewed->slug)
            ->assertJsonPath('data.casinos.0.credit', 'reviewer')
            ->assertJsonCount(1, 'data.casinos')
            ->assertJsonPath('data.articles.0.slug', $live->slug)
            ->assertJsonCount(1, 'data.articles');
    }

    public function test_sitemap_lists_visible_authors_only(): void
    {
        [$site, $key] = $this->siteWithKey();
        $visible = Author::factory()->create(['site_id' => $site->id]);
        Author::factory()->hidden()->create(['site_id' => $site->id]);

        $slugs = array_column(
            $this->getJson($this->publicBase($site) . '/sitemap', $this->siteHeaders($key))->assertOk()->json('data.authors'),
            'slug',
        );

        $this->assertSame([$visible->slug], $slugs);
    }

    // ── Bylines ──────────────────────────────────────────────────────────────

    public function test_casino_byline_is_per_site_and_falls_back_to_the_global_review_date(): void
    {
        [$site, $key] = $this->siteWithKey();
        $writer = Author::factory()->create(['site_id' => $site->id, 'name' => 'Jane Writer']);
        $hidden = Author::factory()->hidden()->create(['site_id' => $site->id]);
        $casino = $this->attachedCasino($site, ['author_id' => $writer->id, 'reviewed_by_id' => $hidden->id]);
        $casino->forceFill(['reviewed_at' => '2026-10-01'])->save();

        $this->getJson($this->publicBase($site) . '/casinos/' . $casino->slug, $this->siteHeaders($key))
            ->assertOk()
            ->assertJsonPath('data.byline.author.name', 'Jane Writer')
            ->assertJsonPath('data.byline.reviewer', null)
            ->assertJsonPath('data.byline.last_reviewed_at', fn (string $d): bool => str_starts_with($d, '2026-10-01'));
    }

    public function test_casino_without_a_named_person_has_no_byline(): void
    {
        [$site, $key] = $this->siteWithKey();
        $casino = $this->attachedCasino($site, ['last_reviewed_at' => now()]);

        $this->getJson($this->publicBase($site) . '/casinos/' . $casino->slug, $this->siteHeaders($key))
            ->assertOk()
            ->assertJsonPath('data.byline', null);
    }

    public function test_casino_byline_endpoint_refuses_an_author_from_another_site_and_survives_sync(): void
    {
        $this->actingAsAdmin();
        $site = Site::factory()->create();
        $casino = $this->attachedCasino($site);
        $foreign = Author::factory()->create();
        $own = Author::factory()->create(['site_id' => $site->id]);

        $this->putJson("/api/v1/admin/casinos/{$casino->id}/sites/{$site->id}/byline", ['author_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('author_id');

        $this->putJson("/api/v1/admin/casinos/{$casino->id}/sites/{$site->id}/byline", ['author_id' => $own->id, 'last_reviewed_at' => '2026-10-20'])
            ->assertOk()
            ->assertJsonPath('data.author_id', $own->id);

        // Saving the attachment table must not wipe it.
        $this->postJson("/api/v1/admin/casinos/{$casino->id}/sites/sync", ['sites' => [['site_id' => $site->id, 'affiliate_url' => 'https://x.test/new']]])
            ->assertOk()
            ->assertJsonPath('data.0.author_id', $own->id);
    }

    public function test_article_byline_is_public_on_the_detail_endpoint(): void
    {
        [$site, $key] = $this->siteWithKey(['news_enabled' => true]);
        $author = Author::factory()->create(['site_id' => $site->id, 'name' => 'Jane Writer']);
        $article = Article::create(['site_id' => $site->id, 'type' => 'news', 'title' => 'A post', 'body' => 'x', 'active' => true, 'published_at' => Carbon::now()->subDay(), 'author_id' => $author->id]);

        $this->getJson($this->publicBase($site) . '/news/' . $article->slug, $this->siteHeaders($key))
            ->assertOk()
            ->assertJsonPath('data.byline.author.slug', $author->slug);
    }

    // ── Page drafts ──────────────────────────────────────────────────────────

    public function test_a_draft_stays_private_until_published(): void
    {
        [$site, $key] = $this->siteWithKey();
        $this->superAdmin();
        $page = CmsPage::factory()->create(['site_id' => $site->id, 'slug' => 'about', 'status' => 'published', 'title' => 'Old', 'content' => '<p>Old.</p>']);

        $this->putJson("/api/v1/admin/pages/{$page->id}", ['draft_title' => 'New', 'draft_content' => '<p>New.</p>'])
            ->assertOk()
            ->assertJsonPath('data.draft_title', 'New')
            ->assertJsonPath('data.title', 'Old');

        $this->getJson($this->publicBase($site) . '/pages/about', $this->siteHeaders($key))->assertJsonPath('data.content', '<p>Old.</p>');
        $this->assertNotNull($page->fresh()->draft_updated_at);

        $this->postJson("/api/v1/admin/pages/{$page->id}/publish-draft")
            ->assertOk()
            ->assertJsonPath('data.title', 'New')
            ->assertJsonPath('data.draft_content', null);

        $this->getJson($this->publicBase($site) . '/pages/about', $this->siteHeaders($key))->assertJsonPath('data.content', '<p>New.</p>');
    }

    public function test_publish_draft_refuses_a_page_without_one(): void
    {
        $this->superAdmin();
        $page = CmsPage::factory()->create(['status' => 'published']);

        $this->postJson("/api/v1/admin/pages/{$page->id}/publish-draft")->assertUnprocessable();
    }

    // ── Commands ─────────────────────────────────────────────────────────────

    public function test_draft_command_drafts_existing_pages_and_creates_missing_ones_unpublished(): void
    {
        $site = Site::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
        $about = CmsPage::factory()->create(['site_id' => $site->id, 'slug' => 'about', 'status' => 'published', 'content' => '<p>Live.</p>']);

        $this->artisan('sites:draft-trust-pages', ['site' => 'acme', '--dry-run' => true])->assertSuccessful();
        $this->assertNull($about->fresh()->draft_content);

        $this->artisan('sites:draft-trust-pages', ['site' => 'acme'])->assertSuccessful();

        $this->assertSame('<p>Live.</p>', $about->fresh()->content);
        $this->assertStringContainsString('independent', (string) $about->fresh()->draft_content);
        $rate = CmsPage::query()->where('site_id', $site->id)->where('slug', 'how-we-rate')->firstOrFail();
        $this->assertSame(CmsPage::STATUS_DRAFT, $rate->status);
    }

    public function test_tokenize_command_replaces_given_text_and_writes_nothing_on_dry_run(): void
    {
        $site = Site::factory()->create(['slug' => 'acme']);
        $other = Site::factory()->create();
        $page = CmsPage::factory()->create(['site_id' => $site->id, 'slug' => 'terms', 'content' => '<p>Operated by SW1Y 4PH. Laws of [governing-law jurisdiction].</p>']);
        $untouched = CmsPage::factory()->create(['site_id' => $other->id, 'slug' => 'terms', 'content' => '<p>Operated by SW1Y 4PH.</p>']);

        $this->artisan('sites:tokenize-legal-identity', ['site' => 'acme', '--entity-was' => ['SW1Y 4PH'], '--dry-run' => true])->assertSuccessful();
        $this->assertStringContainsString('SW1Y 4PH', $page->fresh()->content);

        $this->artisan('sites:tokenize-legal-identity', ['site' => 'acme', '--entity-was' => ['SW1Y 4PH']])->assertSuccessful();

        $this->assertSame('<p>Operated by {{legal_entity_name}}. Laws of {{governing_law}}.</p>', $page->fresh()->content);
        $this->assertSame('<p>Operated by SW1Y 4PH.</p>', $untouched->fresh()->content);
    }
}
