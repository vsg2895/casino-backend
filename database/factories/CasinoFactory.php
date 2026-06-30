<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Casino;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Casino> */
class CasinoFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company() . ' Casino';

        return [
            'name'             => $name,
            'slug'             => Str::slug($name),
            'image_path'       => null,
            'banner_image'     => null,
            'bonuses'          => fake()->randomElement(['100% up to $500', '500$ + 180 Free Spins', '200% + 100 FS', '50 Free Spins No Deposit']),
            'affiliate_url'    => 'https://' . Str::slug($name) . '.example/play',
            'description'      => implode("\n\n", fake()->paragraphs(3)),
            'rating'           => fake()->numberBetween(3, 5),
            'sort_order'       => fake()->numberBetween(0, 20),
            'meta_title'       => $name . ' Review — Bonuses, Games & Rating',
            'meta_description' => fake()->sentence(20),
            'active'           => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
