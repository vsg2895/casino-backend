<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Casino;
use App\Models\Category;
use App\Models\Site;
use App\Models\SpecialOffer;
use Illuminate\Database\Seeder;

class CasinoSeeder extends Seeder
{
    public function run(): void
    {
        $sites       = Site::all();
        $categoryIds = Category::pluck('id')->all();

        $casinos = Casino::factory(10)->create();

        $casinos->each(function (Casino $casino, int $index) use ($sites, $categoryIds): void {
            // Attach to every registered site with per-site affiliate URLs + overrides.
            foreach ($sites as $site) {
                $casino->sites()->attach($site->id, [
                    'affiliate_url' => 'https://' . $site->domain . '/go/' . $casino->slug,
                    'position'      => $index,
                    'featured'      => $index < 3,
                    'active'        => true,
                ]);
            }

            // Random categories.
            $casino->categories()->sync(
                collect($categoryIds)->shuffle()->take(rand(1, 3))->all()
            );

            // One or two special offers per casino; feature the first.
            $offers = SpecialOffer::factory(rand(1, 2))->create(['casino_id' => $casino->id]);
            $casino->update(['featured_special_offer_id' => $offers->first()->id]);
        });

        $this->command?->info(
            '  Seeded ' . $casinos->count() . ' casinos attached to ' . $sites->count() . ' sites, with categories and offers.'
        );
    }
}
