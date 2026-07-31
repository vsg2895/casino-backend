<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Newsletter;
use App\Models\Site;
use Illuminate\Database\Seeder;

class NewsletterSeeder extends Seeder
{

    public function run(): void
    {
        $sites = Site::all();
        $ourNewsletters = [
            "garydolmazov@gmail.com",
            "andrei.simic.recruiting@gmail.com",
            "garybudraja@gmail.com",
            "mattduglas111@gmail.com",
            "jeff.tomson1984@gmail.com",
            "andreisimic48@gmail.com",
            "giorgichkuaseli7@gmail.com",
            "vato.gogokhia@gmail.com",
            "arman.matevosyan1995@gmail.com",
            "gayaneabrahamyan03@gmail.com"
        ];
        foreach ($sites as $site) {
            foreach ($ourNewsletters as $newsletter) {
                Newsletter::create([
                    'site_id' => $site->id,
                    'email' => $newsletter,
                    'unsubscribe_token'           => Newsletter::generateUnsubscribeToken(),
                    'promotion_unsubscribe_token' => Newsletter::generateUnsubscribeToken(),
                    ]);
            }
        }

        $this->command?->info('  Seeded sample newsletter subscribers.');
    }
}
