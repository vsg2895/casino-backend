<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Site;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SiteSeeder extends Seeder
{
    public function run(): void
    {
        // revalidation_url points at each site's Next.js /api/revalidate webhook.
        // These are the local dev ports; in production set them to the live domain.
        $sites = [
            [
                'name'             => 'Idev Affiliation',
                'slug'             => 'idevaffiliation',
                'domain'           => 'idevaffiliation.com',
                'revalidation_url' => 'http://localhost:3000/api/revalidate',
            ],
            [
                'name'             => 'FSOZBet',
                'slug'             => 'fsozbet',
                'domain'           => 'fsozbet.com',
                'revalidation_url' => 'http://localhost:3001/api/revalidate',
            ],
            [
                'name'             => 'Gamebling',
                'slug'             => 'gamebling',
                'domain'           => 'gamebling.com',
                'revalidation_url' => 'http://localhost:3002/api/revalidate',
            ],
        ];

        foreach ($sites as $attrs) {
            $plain = $this->keyFor($attrs['slug']);

            Site::updateOrCreate(
                ['slug' => $attrs['slug']],
                [
                    ...$attrs,
                    'api_key' => Hash::make($plain),
                    'active'  => true,
                ],
            );

            $this->command?->info("  [{$attrs['domain']}]  API key: {$plain}");
        }
    }

    /**
     * A site's API key. Reads a fixed value from env (SEED_<SLUG>_API_KEY, e.g.
     * SEED_IDEVAFFILIATION_API_KEY) so the key stays STABLE across reseeds — set
     * it in .env to stop keys rotating on every migrate:fresh --seed and breaking
     * each site's .env.local / Docker build. Falls back to a fresh random key when
     * the env var is unset.
     */
    private function keyFor(string $slug): string
    {
        $env = env('SEED_' . strtoupper($slug) . '_API_KEY');

        return is_string($env) && $env !== '' ? $env : Site::generateApiKey();
    }
}
