<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\Category;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\SpecialOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class AdminCatalogTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    // ── Categories ────────────────────────────────────────────────────────

    public function test_admin_can_create_list_and_delete_categories(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/categories', ['name' => 'Free Spins'])
            ->assertCreated()->assertJsonPath('data.name', 'Free Spins')->assertJsonPath('data.slug', 'free-spins');

        $this->getJson('/api/v1/admin/categories')->assertOk()->assertJsonCount(1, 'data');

        $category = Category::firstWhere('slug', 'free-spins');
        $this->deleteJson("/api/v1/admin/categories/{$category->id}")->assertNoContent();
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_category_requires_a_name(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/categories', [])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    // ── Special offers ────────────────────────────────────────────────────

    public function test_special_offer_slug_regenerates_when_the_title_changes(): void
    {
        $this->actingAsAdmin();
        $casino = Casino::factory()->create();

        $id = $this->postJson('/api/v1/admin/special-offers', [
            'casino_id' => $casino->id,
            'title'     => 'Welcome Offer',
            'rating'    => 5,
        ])->assertCreated()->json('data.id');

        $original = SpecialOffer::find($id)->slug;
        $this->assertMatchesRegularExpression('/^welcome_offer_[a-z]{6}$/', $original);

        // Rename → slug regenerates from the new title (with a fresh letters suffix).
        $this->putJson("/api/v1/admin/special-offers/{$id}", ['title' => 'Summer Bonus'])
            ->assertOk()->assertJsonPath('data.title', 'Summer Bonus');
        $renamed = SpecialOffer::find($id)->slug;
        $this->assertMatchesRegularExpression('/^summer_bonus_[a-z]{6}$/', $renamed);
        $this->assertNotSame($original, $renamed, 'slug must change when the title changes');

        // A non-title update leaves the slug untouched.
        $this->putJson("/api/v1/admin/special-offers/{$id}", ['rating' => 4])->assertOk();
        $this->assertSame($renamed, SpecialOffer::find($id)->slug, 'slug stays stable when title is unchanged');
    }

    public function test_special_offer_slug_is_name_based_with_a_unique_suffix(): void
    {
        $this->actingAsAdmin();
        $casino = Casino::factory()->create();

        $make = fn () => SpecialOffer::find(
            $this->postJson('/api/v1/admin/special-offers', [
                'casino_id' => $casino->id,
                'title'     => 'Welcome Bonus',
                'rating'    => 5,
            ])->assertCreated()->json('data.id')
        )->slug;

        $slugA = $make();
        $slugB = $make();

        // Both are title-based with underscores...
        $this->assertStringStartsWith('welcome_bonus_', $slugA);
        $this->assertStringStartsWith('welcome_bonus_', $slugB);
        // ...but each carries its own unique letters-only suffix (no "-1"/"-2").
        $this->assertNotSame($slugA, $slugB);
        $this->assertMatchesRegularExpression('/^welcome_bonus_[a-z]{6}$/', $slugA);
    }

    public function test_admin_can_duplicate_a_special_offer(): void
    {
        $this->actingAsAdmin();
        $offer = SpecialOffer::factory()->create(['casino_id' => Casino::factory()->create()->id, 'title' => 'Original']);

        $response = $this->postJson("/api/v1/admin/special-offers/{$offer->id}/duplicate")->assertCreated();

        $copyId = $response->json('data.id');
        $this->assertNotSame($offer->id, $copyId);
        $this->assertStringContainsString('Copy', (string) $response->json('data.title'));
        $this->assertNotSame($offer->slug, SpecialOffer::find($copyId)->slug);
        $this->assertSame($offer->casino_id, SpecialOffer::find($copyId)->casino_id);
    }

    public function test_special_offer_requires_an_existing_casino(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/special-offers', ['casino_id' => 99999, 'title' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('casino_id');
    }

    // ── Newsletter (admin) ────────────────────────────────────────────────

    public function test_admin_can_create_and_list_newsletter_subscribers(): void
    {
        $this->actingAsAdmin();
        $site = Site::factory()->create();

        $this->postJson('/api/v1/admin/newsletters', ['site_id' => $site->id, 'email' => 'a@example.test'])
            ->assertCreated();

        $this->getJson('/api/v1/admin/newsletters')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('newsletters', ['site_id' => $site->id, 'email' => 'a@example.test']);
    }

    public function test_admin_can_export_newsletters_as_csv(): void
    {
        $this->actingAsAdmin();
        $site = Site::factory()->create();
        Newsletter::create(['site_id' => $site->id, 'email' => 'export@example.test']);

        $response = $this->get('/api/v1/admin/newsletters/export');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('export@example.test', $response->streamedContent());
    }

    public function test_admin_can_soft_delete_a_single_subscriber(): void
    {
        $this->actingAsAdmin();
        $site = Site::factory()->create();
        $n = Newsletter::create(['site_id' => $site->id, 'email' => 'del@example.test']);

        $this->deleteJson("/api/v1/admin/newsletters/{$n->id}")->assertNoContent();

        $this->assertSoftDeleted('newsletters', ['id' => $n->id]);
        // Soft-deleted rows disappear from the admin list.
        $this->getJson('/api/v1/admin/newsletters')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_can_bulk_soft_delete_selected_subscribers(): void
    {
        $this->actingAsAdmin();
        $site = Site::factory()->create();
        $a = Newsletter::create(['site_id' => $site->id, 'email' => 'a@example.test']);
        $b = Newsletter::create(['site_id' => $site->id, 'email' => 'b@example.test']);
        $c = Newsletter::create(['site_id' => $site->id, 'email' => 'c@example.test']);

        $this->postJson('/api/v1/admin/newsletters/bulk-delete', ['ids' => [$a->id, $b->id]])
            ->assertOk()->assertJsonPath('deleted', 2);

        $this->assertSoftDeleted('newsletters', ['id' => $a->id]);
        $this->assertSoftDeleted('newsletters', ['id' => $b->id]);
        $this->assertNotSoftDeleted('newsletters', ['id' => $c->id]);
    }

    public function test_bulk_delete_requires_ids(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/newsletters/bulk-delete', ['ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    public function test_admin_can_delete_all_subscribers_for_one_site_only(): void
    {
        $this->actingAsAdmin();
        $site = Site::factory()->create();
        $other = Site::factory()->create();
        Newsletter::create(['site_id' => $site->id, 'email' => 'x@example.test']);
        Newsletter::create(['site_id' => $site->id, 'email' => 'y@example.test']);
        $kept = Newsletter::create(['site_id' => $other->id, 'email' => 'z@example.test']);

        $this->postJson('/api/v1/admin/newsletters/delete-all', ['site_id' => $site->id])
            ->assertOk()->assertJsonPath('deleted', 2);

        $this->assertSame(0, Newsletter::where('site_id', $site->id)->count());
        $this->assertNotSoftDeleted('newsletters', ['id' => $kept->id]);
    }

    public function test_trashed_index_lists_only_soft_deleted_subscribers(): void
    {
        $this->actingAsAdmin();
        $site = Site::factory()->create();
        Newsletter::create(['site_id' => $site->id, 'email' => 'active@example.test']);
        $trashed = Newsletter::create(['site_id' => $site->id, 'email' => 'trashed@example.test']);
        $trashed->delete();

        $res = $this->getJson('/api/v1/admin/newsletters?trashed=1')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('trashed@example.test', $res->json('data.0.email'));
        $this->assertNotNull($res->json('data.0.deleted_at'));
    }

    public function test_admin_can_restore_a_single_subscriber(): void
    {
        $this->actingAsAdmin();
        $site = Site::factory()->create();
        $n = Newsletter::create(['site_id' => $site->id, 'email' => 'r@example.test']);
        $n->delete();

        $this->postJson("/api/v1/admin/newsletters/{$n->id}/restore")
            ->assertOk()->assertJsonPath('data.email', 'r@example.test');

        $this->assertNotSoftDeleted('newsletters', ['id' => $n->id]);
    }

    public function test_admin_can_bulk_restore_subscribers(): void
    {
        $this->actingAsAdmin();
        $site = Site::factory()->create();
        $a = Newsletter::create(['site_id' => $site->id, 'email' => 'a@example.test']);
        $b = Newsletter::create(['site_id' => $site->id, 'email' => 'b@example.test']);
        $a->delete();
        $b->delete();

        $this->postJson('/api/v1/admin/newsletters/restore', ['ids' => [$a->id, $b->id]])
            ->assertOk()->assertJsonPath('restored', 2);

        $this->assertNotSoftDeleted('newsletters', ['id' => $a->id]);
        $this->assertNotSoftDeleted('newsletters', ['id' => $b->id]);
    }

    public function test_admin_can_permanently_delete_a_trashed_subscriber(): void
    {
        $this->actingAsAdmin();
        $site = Site::factory()->create();
        $n = Newsletter::create(['site_id' => $site->id, 'email' => 'gone@example.test']);
        $n->delete();

        $this->deleteJson("/api/v1/admin/newsletters/{$n->id}/force")->assertNoContent();
        $this->assertDatabaseMissing('newsletters', ['id' => $n->id]);
    }

    public function test_admin_can_bulk_permanently_delete_trashed_subscribers(): void
    {
        $this->actingAsAdmin();
        $site = Site::factory()->create();
        $a = Newsletter::create(['site_id' => $site->id, 'email' => 'a@example.test']);
        $b = Newsletter::create(['site_id' => $site->id, 'email' => 'b@example.test']);
        $a->delete();
        $b->delete();

        $this->postJson('/api/v1/admin/newsletters/force-delete', ['ids' => [$a->id, $b->id]])
            ->assertOk()->assertJsonPath('deleted', 2);

        $this->assertDatabaseMissing('newsletters', ['id' => $a->id]);
        $this->assertDatabaseMissing('newsletters', ['id' => $b->id]);
    }
}
