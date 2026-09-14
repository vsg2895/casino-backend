<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        /*
         * AdminUserSeeder is NOT in this list, and must not be added back.
         *
         * It was, until it became destructive: it now deletes every user, every
         * personal access token and every role assignment before creating the one
         * known login. That is correct for a recovery tool invoked deliberately,
         * and catastrophic as a step inside a blanket `db:seed` — which is a
         * routine deploy command, and would silently sign out and delete the
         * production admin.
         *
         *   php artisan db:seed --class=AdminUserSeeder --force
         */
        $this->call([
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
