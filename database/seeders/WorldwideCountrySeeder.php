<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Continent;
use App\Models\Country;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * The "Worldwide" pseudo-country.
 *
 * A casino that accepts players from everywhere used to need a row in
 * `casino_country` for all 79 countries — 79 clicks per casino, and 79 more
 * every time a country is added. Attaching it to Worldwide instead says the same
 * thing once, and the public country queries treat it as a wildcard: a Worldwide
 * casino is listed under every country, not only under its own page.
 *
 * It is a real `countries` row rather than a boolean on `casinos` on purpose.
 * The admin already has a Casino → Countries picker, the pivot already exists,
 * and the public filter already renders whatever the API returns — modelling it
 * as a country means every one of those works with no special case. The
 * precedent is already in the data: "Europe" (code EU) is a countries row too.
 *
 * `continent_id` is NOT NULL, so Worldwide gets its own continent at position 0.
 * That also puts it first in the filter, which is where an "everywhere" option
 * belongs.
 *
 * SAFE TO RE-RUN, and safe in production: everything is matched on slug and
 * updated in place, so it never duplicates and never disturbs the attachments
 * an operator has already made.
 *
 *   php artisan db:seed --class=WorldwideCountrySeeder --force
 */
class WorldwideCountrySeeder extends Seeder
{
    private const string FLAG_PATH = 'flags/worldwide.svg';

    public function run(): void
    {
        $continent = Continent::firstOrNew(['slug' => Country::WORLDWIDE_SLUG]);
        $continent->fill([
            'name'     => 'Worldwide',
            // Ahead of Europe (10), so it heads the grouped filter.
            'position' => 0,
        ])->save();

        $country = Country::firstOrNew(['slug' => Country::WORLDWIDE_SLUG]);

        // `name` is only set on creation. Renaming it in the admin is a
        // legitimate thing to do (a site may prefer "All countries"), and a
        // seeder re-run must not undo the operator's wording.
        if (! $country->exists) {
            $country->name = 'Worldwide';
            $country->slug = Country::WORLDWIDE_SLUG;
        }

        $country->fill([
            'continent_id' => $continent->id,
            // Not an ISO code — no such country. WW is unassigned in ISO 3166-1
            // and is the conventional placeholder, so it cannot collide with a
            // real country added later.
            'code'       => 'WW',
            'image_path' => self::FLAG_PATH,
            'position'   => 0,
        ]);

        // `active` is only forced on creation: an operator who switches Worldwide
        // off has done so deliberately, and re-running the seeder must not
        // silently switch it back on across every site.
        if (! $country->exists) {
            $country->active = true;
        }

        $country->save();

        $this->writeFlag();

        $this->command?->info(sprintf(
            'Worldwide country ready (id %d, %s).',
            $country->id,
            $country->active ? 'active' : 'INACTIVE — switch it on in the admin',
        ));
    }

    /**
     * Put the globe on disk if it is not already there.
     *
     * Flags live in storage, not in the repository — the other 79 are downloaded
     * by `countries:fetch-flags`, which has no source for a country that does not
     * exist. Writing it here is what makes the seeder enough on a fresh
     * production box; an existing file is left alone so a designer's replacement
     * survives a re-run.
     */
    private function writeFlag(): void
    {
        if (Storage::disk('public')->exists(self::FLAG_PATH)) {
            $this->command?->line('Globe icon already present — left as it is.');

            return;
        }

        Storage::disk('public')->put(self::FLAG_PATH, <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-label="Worldwide">
              <circle cx="32" cy="32" r="30" fill="#2f6f9f"/>
              <g fill="none" stroke="#ffffff" stroke-width="2.4" stroke-linecap="round">
                <circle cx="32" cy="32" r="30"/>
                <ellipse cx="32" cy="32" rx="13" ry="30"/>
                <line x1="2"  y1="32" x2="62" y2="32"/>
                <path d="M6.5 17h51M6.5 47h51"/>
              </g>
            </svg>
            SVG);

        $this->command?->info('Globe icon written to storage/app/public/' . self::FLAG_PATH);
    }
}
