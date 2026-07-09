<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            SiteSeeder::class,
            SiteEmailTemplateSeeder::class,
            SiteVerifyEmailSeeder::class,
            SitePromotionEmailSeeder::class,
            CategorySeeder::class,
            // Real casinos + offers scraped from crystaldice.net (replaces the
            // old demo CasinoSeeder/CasinoImageSeeder). Casinos are attached to
            // every site and mapped to categories; one featured offer per casino.
            CasinoScrapeSeeder::class,
            SpecialOfferScrapeSeeder::class,
            NewsletterSeeder::class,
            UnsubscribeSeeder::class,
            SocialLinkSeeder::class,
            CmsPageSeeder::class,
        ]);
    }
}
