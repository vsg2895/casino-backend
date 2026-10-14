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

    /**
     * The admin's "copy public link" must point at THIS environment.
     *
     * It was hardcoded to https://{domain}, so an operator working locally
     * copied a production URL and opened the live site instead of the change
     * they had just made. Derived from `revalidation_url` now — the origin the
     * backend already knows this site's Next.js app is served from.
     */
    public function test_the_admin_link_origin_follows_the_environment(): void
    {
        $site = \App\Models\Site::factory()->create([
            'slug'             => 'winpalack',
            'domain'           => 'winpalack.com',
            'revalidation_url' => 'http://localhost:3002/api/revalidate',
        ]);

        // Scheme, host and port only — the revalidation path is stripped.
        $this->assertSame('http://localhost:3002', $site->adminLinkBaseUrl());

        // Email links are a SEPARATE concern and must stay on the live domain:
        // a verify link pointing at localhost reaches nobody.
        $this->assertSame('https://winpalack.com', $site->frontendBaseUrl());
    }

    public function test_the_admin_link_origin_falls_back_to_the_live_domain(): void
    {
        $site = \App\Models\Site::factory()->create([
            'domain'           => 'example-site.com',
            'revalidation_url' => null,
        ]);

        // A link that opens the real site is wrong but harmless; a link to a
        // port nobody serves is simply broken. Fall back to the safer one.
        $this->assertSame('https://example-site.com', $site->adminLinkBaseUrl());
    }

    /**
     * Renaming an offer MOVES its public URL — and leaves a 301 behind.
     *
     * This asserted the opposite for a while, because a rename used to relocate
     * a live page with nothing to catch the old address: every link and indexed
     * result started 404ing silently. The answer to that was never "freeze the
     * URL" — an editor who renames "2nd Deposit Bonus (Copy)" to "3rd Deposit
     * Bonus" reasonably expects the link to say so — it was to write the
     * redirect. See OfferSlugRedirectService.
     */
    public function test_renaming_an_offer_moves_its_url_and_leaves_a_redirect(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $casino = Casino::factory()->create();
        $casino->sites()->attach($site->id, ['active' => true, 'affiliate_url' => 'https://example.com/go']);

        $id = $this->postJson('/api/v1/admin/special-offers', [
            'casino_id' => $casino->id,
            'title'     => '2nd Deposit Bonus (Copy)',
            'rating'    => 5,
        ])->assertCreated()->json('data.id');

        $original = SpecialOffer::find($id)->slug;
        $this->assertMatchesRegularExpression('/^2nd_deposit_bonus_copy_[a-z]{6}$/', $original);

        $this->putJson("/api/v1/admin/special-offers/{$id}", ['title' => '3rd Deposit Bonus'])
            ->assertOk()
            ->assertJsonPath('data.title', '3rd Deposit Bonus');

        $moved = SpecialOffer::find($id)->slug;
        $this->assertMatchesRegularExpression('/^3rd_deposit_bonus_[a-z]{6}$/', $moved, 'the link must carry the new title');

        // And the address it used to answer to still goes somewhere.
        $this->assertDatabaseHas('redirects', [
            'site_id'          => $site->id,
            'source_path'      => "/special-offers/{$original}",
            'destination_path' => "/special-offers/{$moved}",
            'status_code'      => 301,
            'active'           => true,
        ]);
    }

    public function test_a_second_rename_repoints_the_first_redirect(): void
    {
        /*
         * A → B, then B → C. Without re-pointing, the oldest links still
         * redirect to B, which no longer exists: one hop to a 404, and the
         * failure only appears the second time an offer is renamed.
         */
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $casino = Casino::factory()->create();
        $casino->sites()->attach($site->id, ['active' => true, 'affiliate_url' => 'https://example.com/go']);

        $id = $this->postJson('/api/v1/admin/special-offers', [
            'casino_id' => $casino->id,
            'title'     => 'First Name',
            'rating'    => 5,
        ])->assertCreated()->json('data.id');

        $first = SpecialOffer::find($id)->slug;

        $this->putJson("/api/v1/admin/special-offers/{$id}", ['title' => 'Second Name'])->assertOk();
        $second = SpecialOffer::find($id)->slug;

        $this->putJson("/api/v1/admin/special-offers/{$id}", ['title' => 'Third Name'])->assertOk();
        $third = SpecialOffer::find($id)->slug;

        $this->assertDatabaseHas('redirects', [
            'site_id'          => $site->id,
            'source_path'      => "/special-offers/{$first}",
            'destination_path' => "/special-offers/{$third}",
        ]);
        $this->assertDatabaseHas('redirects', [
            'site_id'          => $site->id,
            'source_path'      => "/special-offers/{$second}",
            'destination_path' => "/special-offers/{$third}",
        ]);
        // Nothing redirects away from the address the offer now occupies.
        $this->assertDatabaseMissing('redirects', [
            'site_id'     => $site->id,
            'source_path' => "/special-offers/{$third}",
        ]);
    }

    public function test_an_edit_that_does_not_touch_the_title_leaves_the_url_alone(): void
    {
        $this->actingAsAdmin();
        $casino = Casino::factory()->create();

        $id = $this->postJson('/api/v1/admin/special-offers', [
            'casino_id' => $casino->id,
            'title'     => 'Welcome Offer',
            'rating'    => 5,
        ])->assertCreated()->json('data.id');

        $original = SpecialOffer::find($id)->slug;

        $this->putJson("/api/v1/admin/special-offers/{$id}", ['rating' => 4])->assertOk();

        $this->assertSame($original, SpecialOffer::find($id)->slug);
        $this->assertSame(0, \App\Models\Redirect::query()->count(), 'no rename, no redirect');
    }

    public function test_a_hand_written_slug_survives_a_rename_in_the_same_save(): void
    {
        // The admin's Link field wins over the title: an address chosen by hand
        // is a decision, not something derived.
        $this->actingAsAdmin();
        $casino = Casino::factory()->create();

        $id = $this->postJson('/api/v1/admin/special-offers', [
            'casino_id' => $casino->id,
            'title'     => 'Welcome Offer',
            'rating'    => 5,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/admin/special-offers/{$id}", [
            'title' => 'Renamed Entirely',
            'slug'  => 'my_chosen_address',
        ])->assertOk()->assertJsonPath('data.slug', 'my_chosen_address');

        $this->assertSame('my_chosen_address', SpecialOffer::find($id)->slug);
    }

    /**
     * The deliberate escape hatch: clearing the slug regenerates it from the
     * current title. That is how an editor moves a URL on purpose — paired
     * with a redirect from the old path — as opposed to by accident.
     */
    public function test_clearing_the_slug_regenerates_it_from_the_current_title(): void
    {
        $this->actingAsAdmin();
        $casino = Casino::factory()->create();

        $offer = SpecialOffer::create([
            'casino_id' => $casino->id,
            'title'     => 'Welcome Offer',
            'rating'    => 5,
        ]);

        $offer->update(['title' => 'Summer Bonus']);
        $offer->slug = '';
        $offer->save();

        $this->assertMatchesRegularExpression('/^summer_bonus_[a-z]{6}$/', $offer->fresh()->slug);
    }

    /**
     * The scenario this behaviour exists for, start to finish.
     *
     * Duplicate "2nd Deposit Bonus" → the copy's address is baked from the copy
     * title, `2nd_deposit_bonus_copy_xxxxxx`. Rename it to "3rd Deposit Bonus"
     * and the address follows, with a 301 left behind on every site it appears
     * on. No second step, no clearing a field: the link says what the bonus is
     * called.
     */
    public function test_a_renamed_duplicate_gets_a_link_that_matches_its_title(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $casino = Casino::factory()->create();
        $casino->sites()->attach($site->id, ['active' => true, 'affiliate_url' => 'https://example.com/go']);

        $id = $this->postJson('/api/v1/admin/special-offers', [
            'casino_id' => $casino->id,
            'title'     => '2nd Deposit Bonus',
            'rating'    => 5,
        ])->assertCreated()->json('data.id');

        $copyId = $this->postJson("/api/v1/admin/special-offers/{$id}/duplicate")
            ->assertCreated()->json('data.id');

        $copySlug = SpecialOffer::find($copyId)->slug;
        $this->assertMatchesRegularExpression('/^2nd_deposit_bonus_copy_[a-z]{6}$/', $copySlug);

        $this->putJson("/api/v1/admin/special-offers/{$copyId}", ['title' => '3rd Deposit Bonus'])->assertOk();

        $this->assertMatchesRegularExpression(
            '/^3rd_deposit_bonus_[a-z]{6}$/',
            SpecialOffer::find($copyId)->slug,
            'the copy wording must not survive in the URL',
        );

        $this->assertDatabaseHas('redirects', [
            'site_id'     => $site->id,
            'source_path' => "/special-offers/{$copySlug}",
        ]);
    }

    public function test_an_editor_can_set_the_slug_and_free_text_is_normalised(): void
    {
        $this->actingAsAdmin();
        $casino = Casino::factory()->create();

        $id = $this->postJson('/api/v1/admin/special-offers', [
            'casino_id' => $casino->id,
            'title'     => 'Welcome Offer',
            'rating'    => 5,
        ])->assertCreated()->json('data.id');

        // Typed as words: the request normalises it exactly as the generator
        // would, so a hand-written address and a generated one look alike.
        $this->putJson("/api/v1/admin/special-offers/{$id}", ['slug' => '3rd Deposit Bonus!'])
            ->assertOk()
            ->assertJsonPath('data.slug', '3rd_deposit_bonus');

        $this->assertSame('3rd_deposit_bonus', SpecialOffer::find($id)->slug);
    }

    public function test_an_address_another_offer_already_owns_is_refused(): void
    {
        $this->actingAsAdmin();
        $casino = Casino::factory()->create();

        $taken = SpecialOffer::create(['casino_id' => $casino->id, 'title' => 'Taken', 'rating' => 5]);
        $mine  = SpecialOffer::create(['casino_id' => $casino->id, 'title' => 'Mine', 'rating' => 5]);

        $this->putJson("/api/v1/admin/special-offers/{$mine->id}", ['slug' => $taken->slug])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        // And its own address is not a collision with itself.
        $this->putJson("/api/v1/admin/special-offers/{$mine->id}", ['slug' => $mine->slug])->assertOk();
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
