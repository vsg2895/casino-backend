<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Continent;
use App\Models\Country;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Adds Germany and Slovakia to `countries`, and nothing else.
 *
 * A SEPARATE seeder rather than an edit to the main country list: that list was
 * seeded once and has been edited in the admin since — positions reordered,
 * entries switched off — so re-running it would overwrite an operator's work to
 * deliver two rows. This one touches exactly two slugs and leaves the other
 * eighty alone.
 *
 * SAFE TO RE-RUN, and safe in production. Both countries are matched on slug and
 * updated in place, so it can never duplicate them. Two fields are deliberately
 * written only on creation:
 *
 *   - `name`, because renaming a country in the admin is legitimate and a
 *     re-run must not undo it.
 *   - `active`, because an operator who switched a country off did so on
 *     purpose, and a seeder must not switch it back on across every site.
 *
 * POSITIONS are chosen to keep Europe alphabetical. The list steps by ten, so
 * Germany lands between Finland (90) and Gibraltar (100), and Slovakia between
 * Serbia (250) and Slovenia (260) — both gaps were free.
 *
 * FLAGS come from the existing `countries:fetch-flags` command rather than from
 * artwork inlined here. That command already knows the source, the circular
 * crop, the storage disk and the `image_path` convention, and it skips any
 * country that already has an image — so a designer's upload survives a re-run.
 * A box with no outbound network gets the rows anyway and is told the flags are
 * missing; nothing about the countries depends on the file existing.
 *
 *   php artisan db:seed --class=GermanySlovakiaCountrySeeder --force
 */
class GermanySlovakiaCountrySeeder extends Seeder
{
    /**
     * The two countries, with the ISO 3166-1 alpha-2 codes the flag source is
     * keyed by, and the position that keeps Europe in alphabetical order.
     *
     * @var list<array{slug: string, name: string, code: string, position: int}>
     */
    private const array COUNTRIES = [
        ['slug' => 'germany',  'name' => 'Germany',  'code' => 'DE', 'position' => 95],
        ['slug' => 'slovakia', 'name' => 'Slovakia', 'code' => 'SK', 'position' => 255],
    ];

    public function run(): void
    {
        $continent = Continent::where('slug', 'europe')->first();

        if ($continent === null) {
            // `continent_id` is NOT NULL and these two belong to one continent
            // only. Guessing — or creating a second "Europe" — would be worse
            // than stopping and saying so.
            $this->command?->error('No continent with slug "europe". Seed the continents first.');

            return;
        }

        $slugs = [];

        foreach (self::COUNTRIES as $row) {
            $country = Country::firstOrNew(['slug' => $row['slug']]);
            $created = ! $country->exists;

            if ($created) {
                $country->name = $row['name'];
                $country->slug = $row['slug'];
                // Only on creation — see the class docblock.
                $country->active = true;
            }

            $country->fill([
                'continent_id' => $continent->id,
                'code'         => $row['code'],
                'position'     => $row['position'],
            ])->save();

            $slugs[] = $row['slug'];

            $this->command?->line(sprintf(
                '  %-9s %s (id %d, %s)',
                $row['slug'],
                $created ? 'created' : 'already present — updated in place',
                $country->id,
                $country->active ? 'active' : 'INACTIVE — switch it on in the admin',
            ));
        }

        $this->fetchFlags($slugs);
    }

    /**
     * Hand the two new rows to `countries:fetch-flags`.
     *
     * Scoped with `--only` so a seeder for two countries can never re-download
     * eighty. No `--force`: a country that already carries an image keeps it.
     *
     * Never fatal. The rows are the point; the flag is a file that can be
     * fetched later or uploaded in the admin, and a seeder that rolled back two
     * countries because a CDN was unreachable would be the wrong trade.
     *
     * @param  list<string>  $slugs
     */
    private function fetchFlags(array $slugs): void
    {
        if ($this->command === null || $slugs === []) {
            return;
        }

        try {
            $this->command->call('countries:fetch-flags', ['--only' => implode(',', $slugs)]);
        } catch (Throwable $e) {
            $this->command->warn(
                'Could not download the flags: ' . $e->getMessage()
                . ' — the countries exist; run `php artisan countries:fetch-flags --only='
                . implode(',', $slugs) . '` when the box has network, or upload them in the admin.',
            );
        }
    }
}
