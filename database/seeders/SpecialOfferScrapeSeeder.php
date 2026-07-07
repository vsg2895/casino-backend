<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Casino;
use App\Models\SpecialOffer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Seeds the real special offers scraped from crystaldice.net
 * (database/seeders/data/special_offers.json). Each offer is linked to its
 * casino (resolved by affiliate-link host during scraping → `casino_slug`) and
 * carries its own offer image/banner. One offer per casino is set as featured.
 * Idempotent (firstOrNew on casino + title). Affiliate URLs are the real
 * external links — no localhost. Runs after CasinoScrapeSeeder.
 *
 * The slug is set explicitly (title-based + unique letters) because
 * DatabaseSeeder mutes model events and the slug column is NOT NULL + unique.
 */
class SpecialOfferScrapeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(File::get(database_path('seeders/data/special_offers.json')), true);
        $featured = [];
        $count = 0;

        foreach ($rows as $row) {
            $casino = Casino::where('slug', $row['casino_slug'])->first();
            if ($casino === null) {
                continue;
            }

            // Dedup on the affiliate link — unique per offer (titles can collide
            // case-insensitively under the DB collation).
            $offer = SpecialOffer::firstOrNew(['affiliate_url' => $row['affiliate_url']]);
            $offer->fill([
                'casino_id'     => $casino->id,
                'title'         => $row['title'],
                'bonuses'       => $row['bonuses'] ?? null,
                'description'   => $row['description'] ?? null,
                'affiliate_url' => $row['affiliate_url'] ?? null,
                'image_path'    => $row['image_path'] ?? null,
                'banner_image'  => $row['banner_image'] ?? null,
                'rating'        => (int) ($row['rating'] ?? 0),
                'sort_order'    => (int) ($row['sort_order'] ?? 0),
                'active'        => true,
            ]);
            if (blank($offer->slug)) {
                $offer->slug = SpecialOffer::generateUniqueSlug($row['title'], $offer->id);
            }
            $offer->save();

            // First offer per casino becomes its featured offer.
            $featured[$casino->id] ??= $offer->id;
            $count++;
        }

        foreach ($featured as $casinoId => $offerId) {
            Casino::whereKey($casinoId)->update(['featured_special_offer_id' => $offerId]);
        }

        $this->command?->info($count . ' special offers seeded from crystaldice.net.');
    }
}
