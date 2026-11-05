<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Continent;
use App\Models\Country;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * "Countries hidden from search": a per-site set, edited in admin → Sites, that
 * the public countries endpoints report as `noindex`. Countries are shared
 * master data, so one site's set must never show on another.
 */
class CountryNoindexTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** The seeded row when the migrations carry it, otherwise a new one. */
    private function country(string $name, string $slug): Country
    {
        $existing = Country::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            return $existing;
        }

        $continent = Continent::firstOrCreate(['slug' => 'asia'], ['name' => 'Asia', 'position' => 10]);

        return Country::create([
            'continent_id' => $continent->id,
            'name'         => $name,
            'slug'         => $slug,
            'code'         => mb_strtoupper(mb_substr($slug, 0, 2)),
            'position'     => 10,
            'active'       => true,
        ]);
    }

    /** @return array<string, bool> slug => noindex for vietnam and japan, from the public grid */
    private function gridFlags(Site $site, string $key): array
    {
        $continents = $this->getJson($this->publicBase($site) . '/countries', $this->siteHeaders($key))->assertOk()->json('data');

        return collect($continents)->flatMap(fn (array $c): array => $c['countries'])
            ->whereIn('slug', ['vietnam', 'japan'])->sortByDesc('slug')
            ->mapWithKeys(fn (array $c): array => [$c['slug'] => $c['noindex']])->all();
    }

    public function test_the_admin_set_reaches_this_sites_public_endpoints_only(): void
    {
        Bus::fake([RevalidateNextJsSites::class]);
        [$site, $key] = $this->siteWithKey(['countries_enabled' => true]);
        [$other, $otherKey] = $this->siteWithKey(['countries_enabled' => true]);
        $vietnam = $this->country('Vietnam', 'vietnam');
        $this->country('Japan', 'japan');

        $this->actingAsAdmin();
        $this->putJson("/api/v1/admin/sites/{$site->id}", ['noindex_country_ids' => [$vietnam->id]])
            ->assertOk()
            ->assertJsonPath('data.noindex_country_ids', [$vietnam->id]);

        $this->assertSame(['vietnam' => true, 'japan' => false], $this->gridFlags($site, $key));
        $this->assertSame(['vietnam' => false, 'japan' => false], $this->gridFlags($other, $otherKey));

        $this->getJson($this->publicBase($site) . '/countries/vietnam', $this->siteHeaders($key))
            ->assertOk()->assertJsonPath('data.country.noindex', true);
        $this->getJson($this->publicBase($site) . '/countries/japan', $this->siteHeaders($key))
            ->assertOk()->assertJsonPath('data.country.noindex', false);

        Bus::assertDispatched(RevalidateNextJsSites::class);
    }

    public function test_sending_an_empty_set_clears_it_and_omitting_it_keeps_it(): void
    {
        [$site] = $this->siteWithKey(['countries_enabled' => true]);
        $vietnam = $this->country('Vietnam', 'vietnam');
        $site->noindexCountries()->attach($vietnam->id);

        $this->actingAsAdmin();
        $this->putJson("/api/v1/admin/sites/{$site->id}", ['name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.noindex_country_ids', [$vietnam->id]);

        $this->putJson("/api/v1/admin/sites/{$site->id}", ['noindex_country_ids' => []])
            ->assertOk()->assertJsonPath('data.noindex_country_ids', []);
        $this->assertDatabaseCount('country_site_noindex', 0);
    }

    public function test_an_unknown_country_is_rejected(): void
    {
        [$site] = $this->siteWithKey();

        $this->actingAsAdmin();
        $this->putJson("/api/v1/admin/sites/{$site->id}", ['noindex_country_ids' => [999999]])
            ->assertUnprocessable()->assertJsonValidationErrors('noindex_country_ids.0');
    }
}
