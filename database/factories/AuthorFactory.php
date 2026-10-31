<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Author;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Author> */
class AuthorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id'       => Site::factory(),
            'name'          => fake()->name(),
            'role'          => fake()->jobTitle(),
            'short_bio'     => fake()->sentence(12),
            'long_bio'      => '<p>' . fake()->paragraph() . '</p>',
            'expertise'     => [fake()->word(), fake()->word()],
            'profile_links' => [['label' => 'LinkedIn', 'url' => 'https://www.linkedin.com/in/' . fake()->userName()]],
            'visible'       => true,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn (): array => ['visible' => false]);
    }
}
