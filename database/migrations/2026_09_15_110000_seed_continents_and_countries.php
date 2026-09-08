<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The initial continent and country list.
 *
 * A MIGRATION rather than a seeder, deliberately: production is deployed with
 * `php artisan migrate` and nothing else, so reference data that arrives via a
 * seeder would never reach it. This is the same reasoning that puts the standard
 * legal pages in code rather than in a seed file.
 *
 * The data is inlined rather than read from an app class. A migration that calls
 * into `app/` breaks the day that class is edited, and this list is a historical
 * record of what was inserted on this date — not a live catalogue.
 *
 * Idempotent by slug: `insertOrIgnore` means a re-run inserts nothing and an
 * admin's later edits — a renamed country, an uploaded flag, a reordered
 * position — are never overwritten. Deleting a country in the admin keeps it
 * deleted, because nothing here re-adds a row that already had its slug.
 */
return new class extends Migration
{
    /**
     * Continents in display order, each with its countries in display order.
     *
     * The leading entries with a null code are not countries: the grid carries a
     * Europe-wide card and an "Arab" card, which behave like any other entry but
     * have no ISO code to give them.
     *
     * @var array<string, list<array{0: string, 1: string|null}>>
     */
    private const array CATALOG = [
        'Europe' => [
            ['Europe', 'EU'],
            ['Austria', 'AT'],
            ['Belgium', 'BE'],
            ['Bulgaria', 'BG'],
            ['Croatia', 'HR'],
            ['Cyprus', 'CY'],
            ['Czech Republic', 'CZ'],
            ['Estonia', 'EE'],
            ['Finland', 'FI'],
            ['Gibraltar', 'GI'],
            ['Hungary', 'HU'],
            ['Ireland', 'IE'],
            ['Italy', 'IT'],
            ['Latvia', 'LV'],
            ['Lithuania', 'LT'],
            ['Luxembourg', 'LU'],
            ['Malta', 'MT'],
            ['Monaco', 'MC'],
            ['Netherlands', 'NL'],
            ['Norway', 'NO'],
            ['Poland', 'PL'],
            ['Portugal', 'PT'],
            ['Romania', 'RO'],
            ['Russia', 'RU'],
            ['Serbia', 'RS'],
            ['Slovenia', 'SI'],
            ['Sweden', 'SE'],
            ['Switzerland', 'CH'],
            ['United Kingdom', 'GB'],
        ],
        'America' => [
            ['Argentina', 'AR'],
            ['Brazil', 'BR'],
            ['Canada', 'CA'],
            ['Chile', 'CL'],
            ['Colombia', 'CO'],
            ['Costa Rica', 'CR'],
            ['Mexico', 'MX'],
            ['Panama', 'PA'],
            ['Paraguay', 'PY'],
            ['Peru', 'PE'],
            ['Puerto Rico', 'PR'],
            ['United States', 'US'],
            ['Uruguay', 'UY'],
            ['Venezuela', 'VE'],
        ],
        'Africa' => [
            ['Angola', 'AO'],
            ['Egypt', 'EG'],
            ['Ghana', 'GH'],
            ['Kenya', 'KE'],
            ['Mauritius', 'MU'],
            ['Mozambique', 'MZ'],
            ['Namibia', 'NA'],
            ['Nigeria', 'NG'],
            ['Rwanda', 'RW'],
            ['Seychelles', 'SC'],
            ['South Africa', 'ZA'],
            ['Tanzania', 'TZ'],
            ['Uganda', 'UG'],
            ['Zambia', 'ZM'],
        ],
        'Asia' => [
            ['Arab', null],
            ['Azerbaijan', 'AZ'],
            ['Bahrain', 'BH'],
            ['Bangladesh', 'BD'],
            ['India', 'IN'],
            ['Indonesia', 'ID'],
            ['Israel', 'IL'],
            ['Japan', 'JP'],
            ['Kazakhstan', 'KZ'],
            ['Kuwait', 'KW'],
            ['Macau', 'MO'],
            ['Malaysia', 'MY'],
            ['Oman', 'OM'],
            ['Philippines', 'PH'],
            ['Saudi Arabia', 'SA'],
            ['Singapore', 'SG'],
            ['South Korea', 'KR'],
            ['Thailand', 'TH'],
            ['United Arab Emirates', 'AE'],
            ['Vietnam', 'VN'],
        ],
        'Australia & Oceania' => [
            ['Australia', 'AU'],
            ['New Zealand', 'NZ'],
        ],
    ];

    public function up(): void
    {
        $now = now();
        $continentPosition = 0;

        foreach (self::CATALOG as $continentName => $countries) {
            $continentPosition += 10;
            $continentSlug = Str::slug($continentName);

            DB::table('continents')->insertOrIgnore([
                'name'       => $continentName,
                'slug'       => $continentSlug,
                'position'   => $continentPosition,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Re-read rather than trusting the insert's return: on a re-run the
            // row already existed and insertOrIgnore reports nothing.
            $continentId = DB::table('continents')->where('slug', $continentSlug)->value('id');

            if ($continentId === null) {
                continue;
            }

            // Gaps of 10, so an admin can drop a country between two existing
            // ones without renumbering the rest.
            $position = 0;
            $rows = [];

            foreach ($countries as [$name, $code]) {
                $position += 10;

                $rows[] = [
                    'continent_id' => $continentId,
                    'name'         => $name,
                    'slug'         => Str::slug($name),
                    'code'         => $code,
                    // No flag is shipped: the images are assets, not data. Upload
                    // one per country in the admin, or render from `code`.
                    'image_path'   => null,
                    'position'     => $position,
                    'active'       => true,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ];
            }

            DB::table('countries')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // Only the rows this migration inserted, and only while nothing points at
        // them: a country an admin has since attached casinos to is left alone
        // rather than taken down along with its pivot rows.
        $slugs = [];

        foreach (self::CATALOG as $countries) {
            foreach ($countries as [$name]) {
                $slugs[] = Str::slug($name);
            }
        }

        DB::table('countries')
            ->whereIn('slug', $slugs)
            ->whereNotExists(static function ($sub): void {
                $sub->selectRaw('1')
                    ->from('casino_country')
                    ->whereColumn('casino_country.country_id', 'countries.id');
            })
            ->delete();

        DB::table('continents')
            ->whereIn('slug', array_map(static fn (string $n): string => Str::slug($n), array_keys(self::CATALOG)))
            ->whereNotExists(static function ($sub): void {
                $sub->selectRaw('1')
                    ->from('countries')
                    ->whereColumn('countries.continent_id', 'continents.id');
            })
            ->delete();
    }
};
