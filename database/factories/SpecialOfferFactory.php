<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Casino;
use App\Models\SpecialOffer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SpecialOffer> */
class SpecialOfferFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->company() . ' Offer';

        return [
            'casino_id'       => Casino::factory(),
            'title'           => $title,
            // Name-based slug + a letter-led alphanumeric suffix (matches the model).
            'slug'            => Str::slug($title) . '-' . SpecialOffer::slugToken(),
            'image_path'      => null,
            'banner_image'    => null,
            'bonuses'         => fake()->randomElement(['100 Free Spins On Registration', '200% Welcome Package', 'No Deposit 50 FS']),
            'affiliate_url'   => 'https://' . fake()->domainWord() . '.example/offer',
            'description'     => implode("\n\n", fake()->paragraphs(2)),
            'rating'          => fake()->numberBetween(3, 5),
            'sort_order'      => fake()->numberBetween(0, 10),
            'active'          => true,
        ];
    }
}
