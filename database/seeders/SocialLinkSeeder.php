<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Site;
use App\Models\SocialLink;
use Illuminate\Database\Seeder;

class SocialLinkSeeder extends Seeder
{
    public function run(): void
    {
        // A realistic default set of footer socials per registered site.
        $platforms = ['facebook', 'twitter', 'instagram', 'youtube', 'telegram'];

        $count = 0;
        foreach (Site::all() as $site) {
            foreach ($platforms as $order => $platform) {
                SocialLink::updateOrCreate(
                    ['site_id' => $site->id, 'platform' => $platform],
                    [
                        'label'      => '@' . $site->slug,
                        'url'        => "https://{$platform}.com/{$site->slug}",
                        'sort_order' => $order,
                        'active'     => true,
                    ],
                );
                $count++;
            }
        }

        $this->command?->info("  Seeded {$count} social links across " . Site::count() . ' sites.');
    }
}
