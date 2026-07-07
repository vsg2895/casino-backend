<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Casino;
use App\Models\Category;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Seeds the real casinos scraped from crystaldice.net (database/seeders/data/
 * casinos.json), attaches each to every registered site, and maps its
 * categories. Idempotent (updateOrCreate / sync). URLs are the real external
 * affiliate links — never localhost.
 */
class CasinoScrapeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(File::get(database_path('seeders/data/casinos.json')), true);
        $sites = Site::all();

        foreach ($rows as $row) {
            $casino = Casino::updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name'          => $row['name'],
                    'description'   => $row['description'] ?? null,
                    'image_path'    => $row['image_path'] ?? null,
                    'banner_image'  => $row['banner_image'] ?? null,
                    'bonuses'       => $row['bonuses'] ?? null,
                    'affiliate_url' => $row['affiliate_url'] ?? null,
                    'rating'        => (int) ($row['rating'] ?? 0),
                    'sort_order'    => (int) ($row['sort_order'] ?? 0),
                    'active'        => $row['active'] ?? true,
                ],
            );

            // Attach to every site with the casino's real affiliate link + rank.
            foreach ($sites as $site) {
                $site->casinos()->syncWithoutDetaching([
                    $casino->id => [
                        'affiliate_url' => $row['affiliate_url'] ?: ('https://' . $site->domain),
                        'position'      => (int) ($row['sort_order'] ?? 0),
                        'featured'      => false,
                        'active'        => true,
                    ],
                ]);
            }

            // Map categories (find-or-create by slug), replacing prior links.
            $categoryIds = [];
            foreach ($row['categories'] ?? [] as $name) {
                $categoryIds[] = Category::firstOrCreate(
                    ['slug' => Str::slug($name)],
                    ['name' => $name],
                )->id;
            }
            $casino->categories()->sync($categoryIds);
        }

        foreach ($sites as $site) {
            SiteCache::flushSite($site->id);
        }

        $this->command?->info(count($rows) . ' casinos seeded from crystaldice.net, attached to ' . $sites->count() . ' sites.');
    }
}
