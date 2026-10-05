<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Continent;
use App\Models\Country;
use Database\Seeders\GermanySlovakiaCountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The two-country seeder.
 *
 * What these tests actually defend is RE-RUNNABILITY. The seeder is meant to be
 * safe on production, where the country list has already been edited by hand —
 * so running it twice must not duplicate a row, must not undo a rename, and
 * must not switch a country back on that an operator deliberately switched off.
 *
 * NO NETWORK. The seeder hands its new rows to `countries:fetch-flags`, which
 * downloads artwork; Http::fake() keeps that off the wire here while still
 * exercising the call.
 */
class GermanySlovakiaCountrySeederTest extends TestCase
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
        $this->seed(GermanySlovakiaCountrySeeder::class);
    }

    public function test_it_creates_both_countries_in_europe(): void
    {
        $this->seedCountries();

        $germany = Country::where('slug', 'germany')->sole();
        $slovakia = Country::where('slug', 'slovakia')->sole();

        $this->assertSame('Germany', $germany->name);
        $this->assertSame('DE', $germany->code);
        $this->assertSame('Slovakia', $slovakia->name);
        $this->assertSame('SK', $slovakia->code);

        $europe = Continent::where('slug', 'europe')->sole();
        $this->assertSame($europe->id, $germany->continent_id);
        $this->assertSame($europe->id, $slovakia->continent_id);
    }

    public function test_it_adds_only_those_two(): void
    {
        // "Only Germany and Slovakia" is the whole brief. A migration already
        // populates the country list, so the measure is the DELTA, not the
        // total — and which slugs appeared, not merely how many.
        $before = Country::pluck('slug')->all();

        $this->seedCountries();

        $added = array_values(array_diff(Country::pluck('slug')->all(), $before));
        sort($added);

        $this->assertSame(['germany', 'slovakia'], $added);
    }

    public function test_the_positions_keep_europe_alphabetical(): void
    {
        // The list steps by ten; these two land in the gaps between Finland and
        // Gibraltar, and between Serbia and Slovenia.
        $this->seedCountries();

        $this->assertSame(95, Country::where('slug', 'germany')->value('position'));
        $this->assertSame(255, Country::where('slug', 'slovakia')->value('position'));
    }

    public function test_running_it_twice_duplicates_nothing(): void
    {
        $this->seedCountries();
        $afterFirstRun = Country::count();

        $this->seedCountries();

        $this->assertSame(1, Country::where('slug', 'germany')->count());
        $this->assertSame(1, Country::where('slug', 'slovakia')->count());
        $this->assertSame($afterFirstRun, Country::count(), 'a second run must add nothing');
    }

    public function test_a_re_run_does_not_undo_an_admin_rename(): void
    {
        // Renaming a country in the admin is legitimate; a seeder must not
        // silently put its own wording back.
        $this->seedCountries();
        Country::where('slug', 'germany')->update(['name' => 'Deutschland']);

        $this->seedCountries();

        $this->assertSame('Deutschland', Country::where('slug', 'germany')->value('name'));
    }

    public function test_a_re_run_does_not_switch_a_disabled_country_back_on(): void
    {
        // The one that would be most damaging: switching a country off is a
        // deliberate act across every site, and a re-run must respect it.
        $this->seedCountries();
        Country::where('slug', 'slovakia')->update(['active' => false]);

        $this->seedCountries();

        $this->assertFalse((bool) Country::where('slug', 'slovakia')->value('active'));
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
