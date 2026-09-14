<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\CasinoReview;
use App\Models\Category;
use App\Models\CmsPage;
use App\Models\SearchIndexEntry;
use App\Models\Site;
use App\Models\SpecialOffer;
use App\Services\Search\SearchIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * `search:reindex`, plus the two sync paths the observers cannot see.
 *
 * The command is the recovery tool: `search_index` is a cache, so the ability to
 * rebuild it from the entity tables is what makes every other shortcut in the
 * design safe. If this breaks, a bad deploy has no way back.
 */
class SearchReindexCommandTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @return array{0: Site, 1: Casino} */
    private function siteWithCasino(string $name = 'Reindex Casino'): array
    {
        [$site] = $this->siteWithKey();
        $casino = Casino::factory()->create(['name' => $name, 'active' => true]);
        $casino->sites()->attach($site->id, ['affiliate_url' => 'https://x.test', 'active' => true]);

        // The attach fires no model event — the pivot is written directly — so
        // the sync is called explicitly, exactly as CasinoSiteAttachmentController
        // does in the admin. Without this the casino exists but is unindexed,
        // which is precisely the bug that call site prevents.
        app(SearchIndexer::class)->syncCasino($casino->fresh());

        return [$site, $casino->fresh()];
    }

    public function test_it_rebuilds_the_index_from_the_source_tables(): void
    {
        [$site, $casino] = $this->siteWithCasino();
        SpecialOffer::factory()->create(['casino_id' => $casino->id, 'active' => true]);
        CmsPage::factory()->create(['site_id' => $site->id, 'status' => CmsPage::STATUS_PUBLISHED]);

        // Wipe it the way a bad deploy or a truncate would.
        SearchIndexEntry::query()->delete();
        $this->assertSame(0, SearchIndexEntry::count());

        $this->artisan('search:reindex')->assertSuccessful();

        $this->assertGreaterThan(0, SearchIndexEntry::where('section', 'casinos')->count());
        $this->assertGreaterThan(0, SearchIndexEntry::where('section', 'special_offers')->count());
        $this->assertGreaterThan(0, SearchIndexEntry::where('section', 'pages')->count());
    }

    public function test_it_is_idempotent(): void
    {
        [$site, $casino] = $this->siteWithCasino();
        SpecialOffer::factory()->create(['casino_id' => $casino->id, 'active' => true]);

        $this->artisan('search:reindex')->assertSuccessful();
        $first = SearchIndexEntry::count();

        $this->artisan('search:reindex')->assertSuccessful();

        // The unique key makes a re-run an upsert, never a duplicate.
        $this->assertSame($first, SearchIndexEntry::count());
    }

    public function test_a_single_section_can_be_rebuilt(): void
    {
        [$site, $casino] = $this->siteWithCasino();
        SearchIndexEntry::query()->delete();

        $this->artisan('search:reindex', ['--section' => 'pages'])->assertSuccessful();

        $this->assertSame(0, SearchIndexEntry::where('section', 'casinos')->count());
    }

    public function test_an_unknown_section_fails_rather_than_silently_doing_nothing(): void
    {
        $this->artisan('search:reindex', ['--section' => 'nonsense'])->assertFailed();
    }

    public function test_prune_removes_rows_whose_entity_is_gone(): void
    {
        [$site, $casino] = $this->siteWithCasino();
        $this->assertGreaterThan(0, SearchIndexEntry::count());

        // A hard delete straight in the database leaves no model to observe —
        // which is exactly the orphan --prune exists for.
        \DB::table('casinos')->where('id', $casino->id)->delete();

        $this->artisan('search:reindex', ['--prune' => true])->assertSuccessful();

        $this->assertSame(
            0,
            SearchIndexEntry::where('searchable_type', Casino::class)->where('searchable_id', $casino->id)->count(),
        );
    }

    // ── the two paths observers cannot see ───────────────────────────────────

    public function test_attaching_a_casino_to_a_site_indexes_it_for_that_site(): void
    {
        [$siteA, $casino] = $this->siteWithCasino();
        [$siteB] = $this->siteWithKey();

        $this->assertSame(0, SearchIndexEntry::where('site_id', $siteB->id)->where('section', 'casinos')->count());

        // Pivot writes fire NO model events, so the indexer is called explicitly
        // by the admin controllers. This is that call.
        $casino->sites()->attach($siteB->id, ['affiliate_url' => 'https://y.test', 'active' => true]);
        app(SearchIndexer::class)->syncCasino($casino->fresh());

        $this->assertSame(1, SearchIndexEntry::where('site_id', $siteB->id)->where('section', 'casinos')->count());
    }

    public function test_a_category_appears_only_where_one_of_its_casinos_is_visible(): void
    {
        [$site, $casino] = $this->siteWithCasino();
        $category = Category::factory()->create(['name' => 'Live Tables']);

        // A category has no site_id and no active flag — its visibility is
        // DERIVED from whether any attached casino is active on the site.
        $casino->categories()->attach($category->id);
        app(SearchIndexer::class)->syncCasino($casino->fresh());

        $this->assertSame(1, SearchIndexEntry::where('site_id', $site->id)->where('section', 'categories')->count());

        // Hide the only casino: the category must leave with it.
        $casino->update(['active' => false]);

        $this->assertSame(0, SearchIndexEntry::where('site_id', $site->id)->where('section', 'categories')->count());
    }

    public function test_hiding_a_casino_cascades_to_its_offers_and_reviews(): void
    {
        [$site, $casino] = $this->siteWithCasino();
        $offer = SpecialOffer::factory()->create(['casino_id' => $casino->id, 'active' => true]);
        $review = CasinoReview::create([
            'site_id' => $site->id, 'casino_id' => $casino->id, 'author_name' => 'A',
            'rating' => 5, 'body' => 'a review body of adequate length',
            'status' => CasinoReview::STATUS_PUBLISHED,
        ]);

        $this->assertGreaterThan(0, SearchIndexEntry::where('section', 'forum')->count());

        $casino->update(['active' => false]);

        // A review is only reachable through a casino that is on the site, so an
        // orphaned review row would be a leak, not merely stale.
        $this->assertSame(0, SearchIndexEntry::where('searchable_id', $offer->id)->where('searchable_type', SpecialOffer::class)->count());
        $this->assertSame(0, SearchIndexEntry::where('searchable_id', $review->id)->where('searchable_type', CasinoReview::class)->count());
    }

    public function test_an_index_write_retires_the_sites_cached_suggestions(): void
    {
        [$site, $casino] = $this->siteWithCasino();

        $before = SearchIndexer::version((int) $site->id);
        $casino->update(['name' => 'Renamed Casino']);
        $after = SearchIndexer::version((int) $site->id);

        // The cache key carries this version, so bumping it retires the site's
        // cached responses at once. Without it an admin hiding a casino would
        // keep seeing it in search for up to the TTL.
        $this->assertGreaterThan($before, $after);
    }

    public function test_the_version_helper_survives_a_broken_cache(): void
    {
        // A cache outage must never fail an admin save.
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache down'));

        $this->assertSame(0, SearchIndexer::version(1));
    }
}
