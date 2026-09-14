<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\Continent;
use App\Models\Country;
use App\Models\Site;
use Database\Seeders\WorldwideCountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The "Worldwide" wildcard country.
 *
 * The feature only earns its keep if attaching a casino to Worldwide is
 * genuinely equivalent to attaching it to every country — so that is what these
 * assert, from the public endpoints rather than from the query builder.
 *
 * There were no country tests at all before this, so the fixtures are built here
 * rather than borrowed.
 */
class WorldwideCountryTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->site, $this->key] = $this->siteWithKey(['countries_enabled' => true]);
    }

    private function country(string $name, string $slug): Country
    {
        $continent = Continent::firstOrCreate(['slug' => 'europe'], ['name' => 'Europe', 'position' => 10]);

        return Country::create([
            'continent_id' => $continent->id,
            'name'         => $name,
            'slug'         => $slug,
            'code'         => mb_strtoupper(mb_substr($slug, 0, 2)),
            'position'     => 10,
            'active'       => true,
        ]);
    }

    /** A casino visible on the test site. */
    private function casino(string $name): Casino
    {
        $casino = Casino::factory()->create(['name' => $name, 'active' => true]);
        $casino->sites()->attach($this->site->id, [
            'active'        => true,
            'position'      => 1,
            // NOT NULL on the pivot.
            'affiliate_url' => 'https://example.test/go',
        ]);

        return $casino;
    }

    private function listing(string $slug): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(
            $this->publicBase($this->site) . '/countries/' . $slug,
            $this->siteHeaders($this->key),
        );
    }

    /** @return list<string> casino names on a country page */
    private function namesOn(string $slug): array
    {
        return array_column($this->listing($slug)->assertOk()->json('data.casinos'), 'name');
    }

    private function seedWorldwide(): Country
    {
        $this->seed(WorldwideCountrySeeder::class);

        return Country::where('slug', Country::WORLDWIDE_SLUG)->firstOrFail();
    }

    // ── the seeder ───────────────────────────────────────────────────────────

    public function test_the_seeder_creates_the_wildcard_row(): void
    {
        $worldwide = $this->seedWorldwide();

        $this->assertSame('Worldwide', $worldwide->name);
        $this->assertSame('WW', $worldwide->code);
        $this->assertSame('flags/worldwide.svg', $worldwide->image_path);
        $this->assertTrue($worldwide->active);
        $this->assertSame(0, $worldwide->position, 'Worldwide must sort ahead of every real country.');
        $this->assertTrue($worldwide->isWorldwide());
    }

    /**
     * The globe has to be WRITTEN by the seeder, because it is the only thing
     * that puts it on a server.
     *
     * Flags live in storage/, which is not in the repository, and
     * `countries:fetch-flags` has no source for a country that does not exist.
     * If this write ever stops happening, production gets a country whose
     * image_path points at a 404 and the filter shows a broken icon.
     */
    public function test_the_seeder_writes_the_globe_icon_when_it_is_missing(): void
    {
        Storage::fake('public');

        $this->seed(WorldwideCountrySeeder::class);

        Storage::disk('public')->assertExists('flags/worldwide.svg');
        $this->assertStringContainsString('<svg', Storage::disk('public')->get('flags/worldwide.svg'));
    }

    /** A designer's replacement must survive a re-run. */
    public function test_an_existing_globe_icon_is_left_alone(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('flags/worldwide.svg', '<svg id="mine"></svg>');

        $this->seed(WorldwideCountrySeeder::class);

        $this->assertSame('<svg id="mine"></svg>', Storage::disk('public')->get('flags/worldwide.svg'));
    }

    public function test_the_seeder_is_safe_to_run_twice(): void
    {
        $this->seedWorldwide();
        $first = Country::where('slug', Country::WORLDWIDE_SLUG)->firstOrFail();

        $this->seed(WorldwideCountrySeeder::class);

        $this->assertSame(1, Country::where('slug', Country::WORLDWIDE_SLUG)->count());
        $this->assertSame(1, Continent::where('slug', Country::WORLDWIDE_SLUG)->count());
        $this->assertSame($first->id, Country::where('slug', Country::WORLDWIDE_SLUG)->value('id'));
    }

    /**
     * An operator who renames it or switches it off must not have that undone by
     * a deploy that re-runs seeders.
     */
    public function test_a_rerun_preserves_operator_edits(): void
    {
        $worldwide = $this->seedWorldwide();
        $worldwide->update(['name' => 'All countries', 'active' => false]);

        $this->seed(WorldwideCountrySeeder::class);

        $worldwide->refresh();
        $this->assertSame('All countries', $worldwide->name);
        $this->assertFalse($worldwide->active);
    }

    // ── the wildcard itself ──────────────────────────────────────────────────

    public function test_a_worldwide_casino_is_listed_under_a_country_it_is_not_attached_to(): void
    {
        $worldwide = $this->seedWorldwide();
        $germany = $this->country('Germany', 'germany');

        $local = $this->casino('Local Casino');
        $local->countries()->attach($germany->id);

        $global = $this->casino('Global Casino');
        $global->countries()->attach($worldwide->id);   // and nothing else

        $names = $this->namesOn('germany');

        $this->assertContains('Global Casino', $names, 'A Worldwide casino must appear on every country page.');
        $this->assertContains('Local Casino', $names);
    }

    public function test_a_casino_attached_to_both_is_listed_once(): void
    {
        $worldwide = $this->seedWorldwide();
        $germany = $this->country('Germany', 'germany');

        $casino = $this->casino('Both Casino');
        $casino->countries()->attach([$germany->id, $worldwide->id]);

        $names = $this->namesOn('germany');

        $this->assertSame(['Both Casino'], $names);
        $this->assertSame(1, $this->listing('germany')->json('data.meta.total'));
    }

    public function test_the_worldwide_page_lists_only_worldwide_casinos(): void
    {
        $worldwide = $this->seedWorldwide();
        $germany = $this->country('Germany', 'germany');

        $this->casino('Germany Only')->countries()->attach($germany->id);
        $this->casino('Global Casino')->countries()->attach($worldwide->id);

        $this->assertSame(['Global Casino'], $this->namesOn(Country::WORLDWIDE_SLUG));
    }

    public function test_the_country_count_includes_worldwide_casinos_without_double_counting(): void
    {
        $worldwide = $this->seedWorldwide();
        $germany = $this->country('Germany', 'germany');

        $this->casino('Germany Only')->countries()->attach($germany->id);
        $this->casino('Global Casino')->countries()->attach($worldwide->id);
        $this->casino('Both Casino')->countries()->attach([$germany->id, $worldwide->id]);

        $response = $this->getJson(
            $this->publicBase($this->site) . '/countries',
            $this->siteHeaders($this->key),
        )->assertOk();

        $counts = [];
        foreach ($response->json('data') as $continent) {
            foreach ($continent['countries'] ?? [] as $c) {
                $counts[$c['slug']] = $c['casinos_count'];
            }
        }

        // Germany: its own 2 + the worldwide-only one = 3, with "Both" counted once.
        $this->assertSame(3, $counts['germany'] ?? null);
        $this->assertSame(2, $counts[Country::WORLDWIDE_SLUG] ?? null);
    }

    // ── Worldwide is exclusive ───────────────────────────────────────────────

    public function test_selecting_worldwide_drops_every_other_country(): void
    {
        $worldwide = $this->seedWorldwide();
        $germany = $this->country('Germany', 'germany');
        $france = $this->country('France', 'france');

        $casino = $this->casino('Global Casino');
        $casino->syncCountries([$germany->id, $worldwide->id, $france->id]);

        $this->assertSame([$worldwide->id], $casino->countries()->pluck('countries.id')->all());
    }

    public function test_a_list_without_worldwide_is_left_alone(): void
    {
        $this->seedWorldwide();
        $germany = $this->country('Germany', 'germany');
        $france = $this->country('France', 'france');

        $casino = $this->casino('Local Casino');
        $casino->syncCountries([$germany->id, $france->id]);

        $stored = $casino->countries()->pluck('countries.id')->sort()->values()->all();
        $this->assertSame([$germany->id, $france->id], $stored === [] ? [] : $stored);
    }

    /**
     * Switching a worldwide casino back to specific countries has to work, or
     * the rule would be a one-way trap.
     */
    public function test_a_worldwide_casino_can_be_narrowed_back_to_specific_countries(): void
    {
        $worldwide = $this->seedWorldwide();
        $germany = $this->country('Germany', 'germany');

        $casino = $this->casino('Reconsidered');
        $casino->syncCountries([$worldwide->id]);
        $this->assertSame([$worldwide->id], $casino->countries()->pluck('countries.id')->all());

        $casino->syncCountries([$germany->id]);
        $this->assertSame([$germany->id], $casino->countries()->pluck('countries.id')->all());
    }

    public function test_the_rule_applies_through_the_admin_endpoint(): void
    {
        $worldwide = $this->seedWorldwide();
        $germany = $this->country('Germany', 'germany');
        $casino = $this->casino('Via API');

        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/casinos/{$casino->id}", [
            'name'        => $casino->name,
            'country_ids' => [$germany->id, $worldwide->id],
        ])->assertOk();

        $this->assertSame([$worldwide->id], $casino->fresh()->countries()->pluck('countries.id')->all());
    }

    public function test_collapse_deduplicates_and_survives_a_missing_wildcard(): void
    {
        $germany = $this->country('Germany', 'germany');

        // No seeder run: nothing to collapse to, so the list only de-duplicates.
        $this->assertSame(
            [$germany->id],
            Country::collapseWorldwide([$germany->id, $germany->id]),
        );

        $worldwide = $this->seedWorldwide();
        $this->assertSame(
            [$worldwide->id],
            Country::collapseWorldwide([$germany->id, $worldwide->id, $worldwide->id]),
        );
    }

    /**
     * A casino on another site must not leak in through the wildcard — the
     * wildcard widens the COUNTRY condition, never the site condition.
     */
    public function test_the_wildcard_does_not_cross_site_boundaries(): void
    {
        $worldwide = $this->seedWorldwide();
        $germany = $this->country('Germany', 'germany');

        $otherSite = Site::factory()->create();
        $foreign = Casino::factory()->create(['name' => 'Other Site Casino', 'active' => true]);
        $foreign->sites()->attach($otherSite->id, [
            'active' => true, 'position' => 1, 'affiliate_url' => 'https://example.test/go',
        ]);
        $foreign->countries()->attach($worldwide->id);

        $this->assertNotContains('Other Site Casino', $this->namesOn('germany'));
    }

    public function test_an_inactive_worldwide_casino_is_not_listed(): void
    {
        $worldwide = $this->seedWorldwide();
        $this->country('Germany', 'germany');

        $casino = $this->casino('Switched Off');
        $casino->countries()->attach($worldwide->id);
        $casino->update(['active' => false]);

        $this->assertNotContains('Switched Off', $this->namesOn('germany'));
    }

    /**
     * The seeder has not been run on every database. Country pages must behave
     * exactly as they did before the feature existed.
     */
    public function test_everything_still_works_when_the_wildcard_row_is_absent(): void
    {
        $germany = $this->country('Germany', 'germany');
        $this->casino('Germany Only')->countries()->attach($germany->id);

        $this->assertNull(Country::worldwideId());
        $this->assertSame(['Germany Only'], $this->namesOn('germany'));
    }
}
