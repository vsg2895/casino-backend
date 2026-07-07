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
        $sites = [
            ['name' => 'Idev Affiliation', 'slug' => 'idevaffiliation', 'domain' => 'idevaffiliation.com'],
            ['name' => 'Winpalack',        'slug' => 'winpalack',       'domain' => 'winpalack.com'],
        ];

        foreach ($sites as $attrs) {
            // revalidation_url = the site's env-aware public URL (localhost when
            // APP_DEBUG, the live domain otherwise) + the Next.js webhook path.
            $attrs['revalidation_url'] = rtrim(
                (string) config('urls.sites.' . $attrs['slug'], 'https://' . $attrs['domain']),
                '/',
            ) . '/api/revalidate';

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
