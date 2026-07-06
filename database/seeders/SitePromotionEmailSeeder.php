<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Site;
use App\Models\SitePromotionEmail;
use Illuminate\Database\Seeder;

class SitePromotionEmailSeeder extends Seeder
{
    /**
     * Ensure every site has a promotion email template. Uses firstOrCreate by
     * site_id so re-running never overwrites admin edits. Button colours are
     * seeded to match each site's public identity.
     */
    public function run(): void
    {
        $buttons = [
            'idevaffiliation' => '#4f46e5', // indigo — light crystal theme
            'winpalack'       => '#059669', // emerald — responsible play theme
        ];

        Site::all()->each(function (Site $site) use ($buttons): void {
            $defaults = SitePromotionEmail::defaultsFor($site);
            $defaults['button_color'] = $buttons[$site->slug] ?? $defaults['button_color'];

            SitePromotionEmail::firstOrCreate(
                ['site_id' => $site->id],
                $defaults,
            );
        });
    }
}
