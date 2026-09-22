<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Jobs\RevalidateNextJsSites;
use App\Models\BonusCategory;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Database\Seeder;

/**
 * The bonus taxonomy, as a repeatable script.
 *
 * These six rows are the ones the network actually runs on — they were built up
 * by hand in the admin and are the same set every domain needs, because a
 * BonusCategory is GLOBAL: a bonus type describes the offer, not the site
 * showing it. Re-entering them by hand on a fresh environment is the kind of
 * task that produces five categories on one host and six on another, with one
 * slug quietly different.
 *
 * SAFE IN PRODUCTION, unlike the *DemoSeeder classes. Nothing here is sample
 * content: it is the live taxonomy, and it carries no invented bonus amounts,
 * no operator names and no figures.
 *
 * WHAT IT TOUCHES
 * ---------------
 * Only `bonus_categories`, and only the six slugs listed below. It matches on
 * SLUG rather than id — ids differ between environments, and writing to a
 * hard-coded id is how a seeder overwrites whatever happens to occupy that row
 * on the other host. A category that exists is updated in place, so its id, its
 * offers and its URL all survive; anything else in the table is left alone and
 * nothing is ever deleted.
 *
 * It DOES overwrite name, description, position and active on an existing row —
 * that is the point of "make production match", and it is why the run prints
 * what it changed. An editor's own wording in one of these six fields will be
 * replaced, so read the output.
 *
 *   php artisan db:seed --class=BonusCategorySeeder --force
 */
class BonusCategorySeeder extends Seeder
{
    /**
     * The taxonomy, in menu order.
     *
     * `position` is not sequential and deliberately so: it mirrors what is
     * live, where Special Offers leads at 0 and the rest were appended in the
     * twenties as they were added. Renumbering them here would silently
     * reorder the Bonus menu on every site.
     *
     * @var list<array{slug: string, name: string, description: string|null, position: int}>
     */
    private const array CATEGORIES = [
        ['slug' => 'special-offers',    'name' => 'Special Offers',    'description' => null, 'position' => 0],
        ['slug' => 'free-spins',        'name' => 'Free Spins',        'description' => 'Offers whose headline is spins rather than cash.', 'position' => 20],
        ['slug' => 'welcome-bonuses',   'name' => 'Welcome Bonuses',   'description' => null, 'position' => 21],
        ['slug' => 'no-deposit-offers', 'name' => 'No Deposit Offers', 'description' => null, 'position' => 22],
        ['slug' => 'cashback',          'name' => 'Cashback',          'description' => null, 'position' => 23],
        ['slug' => 'reload-bonuses',    'name' => 'Reload Bonuses',    'description' => null, 'position' => 24],
    ];

    public function run(): void
    {
        $created = 0;
        $updated = 0;

        foreach (self::CATEGORIES as $row) {
            $category = BonusCategory::firstOrNew(['slug' => $row['slug']]);
            $existed = $category->exists;

            $category->fill([
                'name'        => $row['name'],
                'description' => $row['description'],
                'position'    => $row['position'],
                'active'      => true,
            ]);

            // Set explicitly rather than left to the model's slug generator.
            // The generator derives the slug from the name and never touches it
            // again, so a category renamed later in the admin would be matched
            // on a slug this seeder could no longer reproduce. The slug IS the
            // identity here — it is in the public URL and in every cache key.
            $category->slug = $row['slug'];

            if (! $category->isDirty() && $existed) {
                $this->command?->line("  = {$row['slug']} — already matches");

                continue;
            }

            $changed = array_keys($category->getDirty());
            $category->save();

            if ($existed) {
                $updated++;
                $this->command?->warn("  ~ {$row['slug']} — updated (" . implode(', ', $changed) . ')');
            } else {
                $created++;
                $this->command?->info("  + {$row['slug']} — created");
            }
        }

        $this->command?->info("Bonus categories: {$created} created, {$updated} updated, " . count(self::CATEGORIES) . ' total in the set.');

        if ($created > 0 || $updated > 0) {
            $this->refresh();
        }
    }

    /**
     * Expire every site that publishes the Bonus area, because these rows are
     * global and a seeded change is invisible until the caches turn over.
     *
     * The same two tags the admin's own save sends: `bonus` is the menu and the
     * home-page sections, `special-offers` because the offers listing shares the
     * cache namespace.
     */
    private function refresh(): void
    {
        $siteIds = Site::query()->where('bonus_enabled', true)->pluck('id')->all();

        foreach ($siteIds as $siteId) {
            SiteCache::flushSite($siteId);
        }

        if ($siteIds === []) {
            $this->command?->line('No site has the Bonus area switched on, so nothing needed revalidating.');

            return;
        }

        RevalidateNextJsSites::dispatch(['bonus', 'special-offers'], $siteIds);
        $this->command?->line('Flushed and revalidated ' . count($siteIds) . ' site(s).');
    }
}
