<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Unsubscribe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->assertJsonStructure(['data' => [['id', 'email', 'type', 'unsubscribed_at']], 'meta' => ['current_page', 'per_page']])
            // Simple-paginated: the screen's total comes from /count.
            ->assertJsonMissingPath('meta.total');

        $this->getJson('/api/v1/admin/unsubscribes/count')->assertOk()->assertExactJson(['total' => 2]);
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

    /**
     * cursor() ignores with(), so this export used to run one `sites` query per
     * row. Sites are now eager-loaded per chunk: a fixed number of queries no
     * matter how many rows, with every row still naming its own site.
     */
    public function test_export_loads_sites_without_a_query_per_row(): void
    {
        $this->actingAsAdmin();
        [$siteA] = $this->siteWithKey();
        [$siteB] = $this->siteWithKey();
        foreach (range(1, 6) as $i) {
            Unsubscribe::record(($i % 2 ? $siteA : $siteB)->id, "row{$i}@example.com", Unsubscribe::TYPE_PROMOTION);
        }

        $response = $this->get('/api/v1/admin/unsubscribes/export');
        DB::enableQueryLog();
        $csv = $response->streamedContent();
        $siteQueries = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], '"sites"'));

        $this->assertCount(1, $siteQueries);
        $siteByEmail = collect(explode("\n", trim($csv)))->skip(1)
            ->mapWithKeys(fn (string $line) => [str_getcsv($line)[0] => str_getcsv($line)[2]]);
        $this->assertSame($siteA->name, $siteByEmail['row1@example.com']);
        $this->assertSame($siteB->name, $siteByEmail['row2@example.com']);
    }

    public function test_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/admin/unsubscribes')->assertUnauthorized();
    }
}
