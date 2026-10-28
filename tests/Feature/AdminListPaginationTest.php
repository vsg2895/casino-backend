<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Admin listings must be reachable to their last row.
 *
 * THE BUG THIS GUARDS. With 16 casinos the admin showed 15 and drew a paginator
 * with a single page — the sixteenth was unreachable from the panel entirely,
 * while the "Total Casinos" badge read 16 because it comes from a separate
 * COUNT and was telling the truth.
 *
 * The cause was on the CLIENT: a PrimeVue table paginating the one API page it
 * had been handed instead of asking for the next. But it could only bite
 * because the server ignored `per_page`, so the table's rows-per-page control
 * changed the request and never the response. These tests pin the server half
 * of that contract: the size the caller asks for is the size it gets, and the
 * meta that drives the paginator describes the WHOLE list, not the page.
 */
class AdminListPaginationTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    public function test_the_casino_listing_reports_every_row_and_pages_through_them(): void
    {
        $this->actingAsAdmin();
        Casino::factory()->count(16)->create();

        // The production shape: 16 casinos against the default page size of 15.
        $first = $this->getJson('/api/v1/admin/casinos')->assertOk();

        $first->assertJsonCount(15, 'data');
        // Simple-paginated: the listing says only that a next page exists, and
        // the paginator is drawn from the dedicated count — 16 rows, two pages.
        $this->assertNotNull($first->json('links.next'), 'page two must be reachable');
        $this->getJson('/api/v1/admin/casinos/count')->assertOk()->assertExactJson(['total' => 16]);

        $second = $this->getJson('/api/v1/admin/casinos?page=2')->assertOk();

        $second->assertJsonCount(1, 'data');
        $second->assertJsonPath('meta.current_page', 2);

        // Every casino is reachable across the two pages, none twice.
        $ids = array_merge(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
        );

        $this->assertCount(16, $ids);
        $this->assertCount(16, array_unique($ids), 'no casino may appear on two pages');
    }

    public function test_the_casino_listing_honours_the_requested_page_size(): void
    {
        // The rows-per-page control sends this. Ignoring it left the table
        // computing page numbers from a size the response never used.
        $this->actingAsAdmin();
        Casino::factory()->count(16)->create();

        $this->getJson('/api/v1/admin/casinos?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonMissingPath('meta.last_page');
    }

    public function test_the_casino_page_size_is_capped(): void
    {
        // A cap, so one request cannot ask for the whole table.
        $this->actingAsAdmin();
        Casino::factory()->count(3)->create();

        $this->getJson('/api/v1/admin/casinos?per_page=99999')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_every_site_is_returned_because_the_list_is_the_site_picker(): void
    {
        /*
         * Sites are not paginated, deliberately.
         *
         * This endpoint is the site picker behind roughly thirty admin controls,
         * not just the Sites screen. Paginating it at 15 meant a sixteenth site
         * would vanish from all of them at once — no error, no empty state, just
         * a domain that could no longer be chosen anywhere in the admin.
         */
        $this->actingAsAdmin();
        Site::factory()->count(18)->create();

        $this->getJson('/api/v1/admin/sites')
            ->assertOk()
            ->assertJsonCount(18, 'data');
    }
}
