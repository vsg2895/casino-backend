<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CmsPage;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CmsPage> */
class CmsPageFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'site_id'          => Site::factory(),
            'slug'             => Str::slug($title) . '-' . fake()->unique()->numberBetween(1, 99999),
            'title'            => rtrim($title, '.'),
            'content'          => fake()->paragraphs(3, true),
            'meta_title'       => rtrim($title, '.'),
            'meta_description' => fake()->sentence(12),
            'status'           => CmsPage::STATUS_DRAFT,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => CmsPage::STATUS_PUBLISHED]);
    }

    public function draft(): static
    {
        return $this->state(['status' => CmsPage::STATUS_DRAFT]);
    }
}
