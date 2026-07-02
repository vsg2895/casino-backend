<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Site;
use App\Models\SiteEmailTemplate;
use Illuminate\Database\Seeder;

class SiteEmailTemplateSeeder extends Seeder
{
    /**
     * Ensure every site has a subscription email template. Uses firstOrCreate by
     * site_id so re-running never overwrites admin edits (and never touches API
     * keys). Accent colours are seeded to match each site's public identity.
     */
    public function run(): void
    {
        $accents = [
            'idevaffiliation' => '#4f46e5', // indigo — light crystal theme
            'fsozbet'     => '#b91c1c', // red — dark sportsbook theme
            'gamebling'   => '#c026d3', // fuchsia — candy arcade theme
        ];

        Site::all()->each(function (Site $site) use ($accents): void {
            $defaults = SiteEmailTemplate::defaultsFor($site);
            $defaults['accent_color'] = $accents[$site->slug] ?? $defaults['accent_color'];

            SiteEmailTemplate::firstOrCreate(
                ['site_id' => $site->id],
                $defaults,
            );
        });
    }
}
