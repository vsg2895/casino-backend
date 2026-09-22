<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Site;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sample data for the site screens that are still empty on a local install.
 *
 * Purpose: make every button in the Sites row lead somewhere with something in
 * it, so the screens can be seen working rather than described. Only the three
 * that are empty are filled — Navigation, Forum, News, CMS pages and the email
 * template already have real content and are left strictly alone.
 *
 * WHAT IT TOUCHES, and nothing else:
 *   - `seo_templates` for this site: the five entity rows, upserted
 *   - `redirects` for this site: three rows, matched on source_path
 *   - `articles` of type GUIDE for this site: three rows, matched on slug
 *
 * No table is truncated. Nothing belonging to another site is read or written.
 * Existing rows for these screens are updated in place, never duplicated, so a
 * second run is a no-op.
 *
 * REFUSES TO RUN IN PRODUCTION. Sample copy on a live domain gets found by a
 * crawler long before it gets found by a person.
 *
 *   php artisan db:seed --class=SiteScreensDemoSeeder
 */
class SiteScreensDemoSeeder extends Seeder
{
    private const string SITE_SLUG = 'winpalack';


    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('SiteScreensDemoSeeder refuses to run in production — this is sample content.');

            return;
        }

        $site = Site::where('slug', self::SITE_SLUG)->first();

        if (! $site) {
            $this->command?->error('No site with slug ' . self::SITE_SLUG . ' — nothing written.');

            return;
        }

        $this->seoTemplates($site);
        $this->redirects($site);
        $this->guides($site);

        $this->command?->line('Guides stay switched OFF for this site — turn them on in Sites → Edit when you want to see the public section.');
    }

    /**
     * SEO patterns — one per entity type.
     *
     * `{{name}}`, `{{site_name}}`, `{{year}}` and `{{month}}` are substituted at
     * render time. An unknown token is left visible on purpose (see SeoResolver),
     * which is how an editor discovers they asked for something that does not
     * exist — so every token used here is a real one.
     */
    private function seoTemplates(Site $site): void
    {
        $patterns = [
            'casino'        => ['{{name}} Review {{year}} — Licence, Payouts & Bonuses | {{site_name}}',
                                'An independent look at {{name}}: who licenses it, how fast it pays, and what the bonus terms actually say.'],
            'special_offer' => ['{{name}} — Terms Stated Up Front | {{site_name}}',
                                'Wagering, minimum deposit and withdrawal caps for {{name}}, written out before you claim.'],
            'category'      => ['{{name}} Casinos {{year}} | {{site_name}}',
                                'Casinos in {{name}}, each checked against its regulator before it earned a place on the list.'],
            'page'          => ['{{name}} | {{site_name}}',
                                '{{name}} for {{site_name}} — last reviewed {{month}} {{year}}.'],
            'listing'       => ['{{name}} | {{site_name}}',
                                'Browse {{name}} on {{site_name}}, updated {{month}} {{year}}.'],
        ];

        foreach ($patterns as $entity => [$title, $description]) {
            DB::table('seo_templates')->updateOrInsert(
                ['site_id' => $site->id, 'entity' => $entity],
                [
                    'title_pattern'       => $title,
                    'description_pattern' => $description,
                    'updated_at'          => Carbon::now(),
                    'created_at'          => Carbon::now(),
                ],
            );
        }

        $this->command?->info(count($patterns) . ' SEO pattern(s) ready.');
    }

    /**
     * Redirects — the three shapes an operator actually creates.
     *
     * A renamed page (301), a retired campaign URL (301) and a temporary
     * diversion (302). `hits` is left at 0 because it is a COUNTER, not a
     * setting: inventing traffic numbers would make the screen lie about what
     * has been visited.
     */
    private function redirects(Site $site): void
    {
        $rows = [
            ['/bonuses', '/special-offers', 301, true],
            ['/promo/summer', '/special-offers', 301, true],
            ['/old-forum', '/forum', 302, true],
        ];

        foreach ($rows as [$from, $to, $code, $active]) {
            DB::table('redirects')->updateOrInsert(
                ['site_id' => $site->id, 'source_path' => $from],
                [
                    'destination_path' => $to,
                    'status_code'      => $code,
                    'active'           => $active,
                    'updated_at'       => Carbon::now(),
                    'created_at'       => Carbon::now(),
                ],
            );
        }

        $this->command?->info(count($rows) . ' redirect(s) ready.');
    }

    /**
     * Three guides — exactly the minimum the section needs to go live.
     *
     * Three rather than one on purpose: the guides screen warns below three, and
     * seeing the warning clear is part of understanding what the number means.
     *
     * Same rule as the news seeder: this is the site's OWN editorial, not
     * invented claims about real operators.
     */
    private function guides(Site $site): void
    {
        $guides = [
            ['what-wagering-requirements-really-cost',
             'What Wagering Requirements Really Cost',
             'The multiplier is the number everyone quotes. Here is what it means once the other four terms are read alongside it.'],
            ['how-to-read-a-licence',
             'How To Read A Licence',
             'Where the number lives, which register to check it against, and what a mismatch between company and domain tells you.'],
            ['withdrawal-limits-worth-checking',
             'Withdrawal Limits Worth Checking',
             'Daily and monthly caps decide how long a win takes to reach you. They are rarely on the banner.'],
        ];

        foreach ($guides as $i => [$slug, $title, $excerpt]) {
            Article::withTrashed()->updateOrCreate(
                ['site_id' => $site->id, 'slug' => $slug],
                [
                    'type'    => Article::TYPE_GUIDE,
                    'title'   => $title,
                    'excerpt' => $excerpt,
                    'body'    => '<p>' . $excerpt . '</p>',
                    'published_at'     => Carbon::now()->subDays(5 + $i * 4)->setTime(10, 0),
                    'position'         => ($i + 1) * 10,
                    'active'           => true,
                    'deleted_at'       => null,
                    'meta_title'       => $title,
                    'meta_description' => $excerpt,
                    // Sample copy must never be indexed, even on a staging host.
                    'noindex' => true,
                ],
            );
        }

        $this->command?->info(count($guides) . ' guide(s) ready, all sample data and noindex.');
    }
}
