<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SpecialOffer;
use App\Models\SpecialOfferSiteContent;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SpecialOfferSiteContent> */
class SpecialOfferSiteContentFactory extends Factory
{
    protected $model = SpecialOfferSiteContent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'special_offer_id' => SpecialOffer::factory(),
            'site_id'     => Site::factory(),
            'description' => '<p>' . fake()->paragraph() . '</p>',
        ];
    }
}
