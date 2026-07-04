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
            SitePromotionEmailSeeder::class,
            CategorySeeder::class,
            CasinoSeeder::class,
            CasinoImageSeeder::class,
            NewsletterSeeder::class,
            UnsubscribeSeeder::class,
            SocialLinkSeeder::class,
            CmsPageSeeder::class,
        ]);
    }
}
