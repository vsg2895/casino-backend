<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Newsletter;
use App\Models\Site;
use App\Models\Unsubscribe;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class UnsubscribeSeeder extends Seeder
{
    /**
     * Opt a few seeded subscribers out of each stream so the admin Unsubscribes
     * list has realistic sample data. Idempotent via Unsubscribe::record().
     */
    public function run(): void
    {
        foreach (Site::all() as $site) {
            $subscribers = Newsletter::where('site_id', $site->id)->take(4)->get();

            foreach ($subscribers as $i => $subscriber) {
                // Alternate the stream so both types appear in the list.
                $type = $i % 2 === 0 ? Unsubscribe::TYPE_SUBSCRIPTION : Unsubscribe::TYPE_PROMOTION;

                Unsubscribe::updateOrCreate(
                    ['site_id' => $site->id, 'email' => $subscriber->email, 'type' => $type],
                    ['unsubscribed_at' => Carbon::now()->subDays($i)],
                );
            }
        }

        $this->command?->info('  Seeded sample unsubscribes.');
    }
}
