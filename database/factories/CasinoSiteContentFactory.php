<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Casino;
use App\Models\CasinoSiteContent;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CasinoSiteContent> */
class CasinoSiteContentFactory extends Factory
{
    protected $model = CasinoSiteContent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'casino_id'   => Casino::factory(),
            'site_id'     => Site::factory(),
            'description' => '<p>' . fake()->paragraph() . '</p>',
        ];
    }
}
