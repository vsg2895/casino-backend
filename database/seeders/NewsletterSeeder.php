<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Newsletter;
use App\Models\Site;
use Illuminate\Database\Seeder;

class NewsletterSeeder extends Seeder
{
    public function run(): void
    {
        $sites = Site::all();

        foreach ($sites as $site) {
            for ($i = 0; $i < 8; $i++) {
                Newsletter::firstOrCreate(
                    [
                        'site_id' => $site->id,
                        'email'   => fake()->unique()->safeEmail(),
                    ],
                    // Set the token explicitly: DatabaseSeeder mutes model events
                    // (WithoutModelEvents), so the model's creating hook won't run.
                    ['unsubscribe_token' => Newsletter::generateUnsubscribeToken()],
                );
            }
        }

        $this->command?->info('  Seeded sample newsletter subscribers.');
    }
}
