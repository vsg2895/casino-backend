<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        // sort_order ascending = priority. The first one is the default selection
        // on the public sites.
        $names = [
            'Most Popular',
            'Best Bonuses',
            'New Casinos',
            'Free Spins',
            'Betting',
        ];

        foreach ($names as $order => $name) {
            Category::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'sort_order' => $order],
            );
        }

        $this->command?->info('  Seeded ' . count($names) . ' categories (with priority order).');
    }
}
