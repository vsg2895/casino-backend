<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Continent;
use App\Models\Country;
use Database\Seeders\DenmarkGreeceCountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Denmark + Greece seeder.
 *
 * Same contract as the Germany/Slovakia one, and the same thing worth
 * defending: RE-RUNNABILITY. It is meant to be safe on production, where the
 * country list has already been edited by hand — so running it twice must not
 * duplicate the row, undo a rename, or switch a country back on that an
 * operator deliberately switched off.
 *
 * NO NETWORK. The seeder hands its new row to `countries:fetch-flags`, which
 * downloads artwork; Http::fake() keeps that off the wire while still
 * exercising the call.
 */
class DenmarkGreeceCountrySeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * BOTH fakes, and the Storage one is not optional.
         *
         * The seeder calls `countries:fetch-flags`, which WRITES the downloaded
         * bytes to the public disk. Faking only the HTTP response left the write
         * real, so running this test replaced the actual flag artwork in
         * storage/app/public/flags with the six-byte stub below — silently, and
         * for every country the seeder touches.
         */
        Storage::fake('public');
        Http::fake(['*' => Http::response('<svg/>', 200)]);
        Continent::create(['name' => 'Europe', 'slug' => 'europe', 'position' => 10]);
    }

    private function seedCountries(): void
    {
        $this->seed(DenmarkGreeceCountrySeeder::class);
    }

    public function test_it_creates_both_countries_in_europe(): void
    {
        $this->seedCountries();

        $europe = Continent::where('slug', 'europe')->sole()->id;

        $denmark = Country::where('slug', 'denmark')->sole();
        $this->assertSame('Denmark', $denmark->name);
        $this->assertSame('DK', $denmark->code);
        $this->assertSame($europe, $denmark->continent_id);
        $this->assertTrue((bool) $denmark->active);

        $greece = Country::where('slug', 'greece')->sole();
        $this->assertSame('Greece', $greece->name);
        $this->assertSame('GR', $greece->code);
        $this->assertSame($europe, $greece->continent_id);
        $this->assertTrue((bool) $greece->active);
    }

    public function test_it_adds_only_those_two(): void
    {
        // A migration already populates the country list, so the measure is the
        // DELTA — and which slug appeared, not merely how many.
        $before = Country::pluck('slug')->all();

        $this->seedCountries();

        $added = array_values(array_diff(Country::pluck('slug')->all(), $before));

        sort($added);

        $this->assertSame(['denmark', 'greece'], $added);
    }

    public function test_the_positions_keep_europe_alphabetical(): void
    {
        // The list steps by ten; these two land in free gaps — Denmark between
        // Czech Republic (70) and Estonia (80), Greece between Gibraltar (100)
        // and Hungary (110).
        $this->seedCountries();

        $this->assertSame(75, Country::where('slug', 'denmark')->value('position'));
        $this->assertSame(105, Country::where('slug', 'greece')->value('position'));
    }

    public function test_running_it_twice_duplicates_nothing(): void
    {
        $this->seedCountries();
        $after = Country::count();

        $this->seedCountries();

        $this->assertSame(1, Country::where('slug', 'denmark')->count());
        $this->assertSame(1, Country::where('slug', 'greece')->count());
        $this->assertSame($after, Country::count(), 'a second run must add nothing');
    }

    public function test_a_re_run_does_not_undo_an_admin_rename(): void
    {
        $this->seedCountries();
        Country::where('slug', 'denmark')->update(['name' => 'Danmark']);

        $this->seedCountries();

        $this->assertSame('Danmark', Country::where('slug', 'denmark')->value('name'));
    }

    public function test_a_re_run_does_not_switch_a_disabled_country_back_on(): void
    {
        // Switching a country off is a deliberate act across every site.
        $this->seedCountries();
        Country::where('slug', 'greece')->update(['active' => false]);

        $this->seedCountries();

        $this->assertFalse((bool) Country::where('slug', 'greece')->value('active'));
    }

    public function test_it_stops_cleanly_when_europe_is_missing(): void
    {
        // `continent_id` is NOT NULL, and guessing a continent — or creating a
        // second "Europe" — would be worse than doing nothing.
        Country::query()->delete();
        Continent::query()->delete();

        $this->seedCountries();

        $this->assertSame(0, Country::count());
    }
}
