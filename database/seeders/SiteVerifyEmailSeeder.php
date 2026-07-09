<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Site;
use App\Models\SiteVerifyEmail;
use Illuminate\Database\Seeder;

class SiteVerifyEmailSeeder extends Seeder
{
    /**
     * Ensure every site has a "verify your email" template. firstOrCreate by
     * site_id so re-running never overwrites admin edits. Accent colours match
     * each site's public identity.
     */
    public function run(): void
    {
        $accents = [
            'idevaffiliation' => '#4f46e5', // indigo — light crystal theme
            'winpalack'       => '#059669', // emerald — responsible play theme
        ];

        Site::all()->each(function (Site $site) use ($accents): void {
            $defaults = SiteVerifyEmail::defaultsFor($site);
            $defaults['accent_color'] = $accents[$site->slug] ?? $defaults['accent_color'];

            SiteVerifyEmail::firstOrCreate(
                ['site_id' => $site->id],
                $defaults,
            );
        });
    }
}
