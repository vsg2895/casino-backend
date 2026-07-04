<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Unsubscribe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class AdminUnsubscribeTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    public function test_index_lists_opt_outs_with_site(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        Unsubscribe::record($site->id, 'a@example.com', Unsubscribe::TYPE_SUBSCRIPTION);
        Unsubscribe::record($site->id, 'b@example.com', Unsubscribe::TYPE_PROMOTION);

        $this->getJson('/api/v1/admin/unsubscribes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.site.id', $site->id)
            ->assertJsonStructure(['data' => [['id', 'email', 'type', 'unsubscribed_at']], 'meta' => ['total']]);
    }

    public function test_index_filters_by_type_and_site_and_search(): void
    {
        $this->actingAsAdmin();
        [$siteA] = $this->siteWithKey();
        [$siteB] = $this->siteWithKey();
        Unsubscribe::record($siteA->id, 'keep@example.com', Unsubscribe::TYPE_PROMOTION);
        Unsubscribe::record($siteA->id, 'other@example.com', Unsubscribe::TYPE_SUBSCRIPTION);
        Unsubscribe::record($siteB->id, 'elsewhere@example.com', Unsubscribe::TYPE_PROMOTION);

        // Filter by site + type.
        $this->getJson("/api/v1/admin/unsubscribes?site_id={$siteA->id}&type=promotion")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'keep@example.com');

        // Search by email fragment.
        $this->getJson('/api/v1/admin/unsubscribes?search=elsewhere')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'elsewhere@example.com');
    }

    public function test_index_ignores_unknown_type_filter(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        Unsubscribe::record($site->id, 'a@example.com', Unsubscribe::TYPE_SUBSCRIPTION);

        $this->getJson('/api/v1/admin/unsubscribes?type=bogus')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_destroy_clears_the_opt_out(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $row = Unsubscribe::record($site->id, 'a@example.com', Unsubscribe::TYPE_SUBSCRIPTION);

        $this->deleteJson("/api/v1/admin/unsubscribes/{$row->id}")->assertNoContent();

        $this->assertDatabasemissing('unsubscribes', ['id' => $row->id]);
    }

    public function test_export_streams_csv(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        Unsubscribe::record($site->id, 'csv@example.com', Unsubscribe::TYPE_PROMOTION);

        $res = $this->get('/api/v1/admin/unsubscribes/export');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('content-type'));
        $this->assertStringContainsString('csv@example.com', $res->streamedContent());
    }

    public function test_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/admin/unsubscribes')->assertUnauthorized();
    }
}
