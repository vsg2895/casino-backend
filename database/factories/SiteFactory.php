<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<Site> */
class SiteFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name'             => $name,
            'slug'             => Str::slug($name),
            'domain'           => Str::slug($name) . '.example.com',
            'api_key'          => Hash::make(Str::random(64)),
            'revalidation_url' => null,
            'settings'         => null,
            'active'           => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
