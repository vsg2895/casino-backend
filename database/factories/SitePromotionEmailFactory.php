<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Site;
use App\Models\SitePromotionEmail;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SitePromotionEmail> */
class SitePromotionEmailFactory extends Factory
{
    public function definition(): array
    {
        // Build a coherent default set off a (possibly new) site.
        $site = Site::factory()->create();

        return [
            'site_id' => $site->id,
            ...SitePromotionEmail::defaultsFor($site),
        ];
    }

    public function forSite(Site $site): static
    {
        return $this->state([
            'site_id' => $site->id,
            ...SitePromotionEmail::defaultsFor($site),
        ]);
    }
}
