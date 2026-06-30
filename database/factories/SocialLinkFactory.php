<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Site;
use App\Models\SocialLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SocialLink> */
class SocialLinkFactory extends Factory
{
    public function definition(): array
    {
        $platform = fake()->randomElement(SocialLink::PLATFORMS);

        return [
            'site_id'    => Site::factory(),
            'platform'   => $platform,
            'label'      => '@' . fake()->userName(),
            'url'        => 'https://' . $platform . '.com/' . fake()->userName(),
            'sort_order' => fake()->numberBetween(0, 10),
            'active'     => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
