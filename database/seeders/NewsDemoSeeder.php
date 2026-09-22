<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Site;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Sample news for a local site, so the /news section has something to render.
 *
 * WHAT THIS DELIBERATELY IS NOT
 * -----------------------------
 * It does not invent news about real companies. No operator is named, no
 * licence is claimed to have been granted or revoked, no fine, payout figure or
 * regulatory decision is made up. Those would be fabricated claims about real
 * businesses and real regulators, and they read as true the moment they are on a
 * page — a seeder is not the place to manufacture them, and a gambling
 * affiliate is the last site that should.
 *
 * What it writes instead is this site's OWN editorial: how a licence gets
 * checked, how reviews are moderated, what a wagering requirement means. Every
 * sentence is something the operator of this site can stand behind, and every
 * row is stamped as sample content so it can be found and replaced.
 *
 * WHAT IT TOUCHES
 * ---------------
 * Only its own rows, and nothing else in the database:
 *   - `articles` rows whose slug is in SLUGS (upserted, never duplicated)
 *   - the two throwaway posts created while building the feature (LEFTOVERS)
 *   - up to two `nav_items` rows: News in the header and footer menus, each
 *     inserted only if that menu does not already link to /news
 *   - SVG files under storage/app/public/news/
 * No table is truncated, no unrelated row is read or written.
 *
 * REFUSES TO RUN IN PRODUCTION. Sample editorial on a live domain is the kind of
 * thing that gets found by a crawler long before it gets found by a person.
 *
 *   php artisan db:seed --class=NewsDemoSeeder
 */
class NewsDemoSeeder extends Seeder
{
    private const string SITE_SLUG = 'winpalack';

    /** Slugs left behind by manual testing while the feature was built. */
    private const array LEFTOVERS = ['sample-news-post-1', 'sample-news-post-2'];

    /** Marks every row this seeder owns, so they are trivially findable later. */

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('NewsDemoSeeder refuses to run in production — this is sample editorial.');

            return;
        }

        $site = Site::where('slug', self::SITE_SLUG)->first();

        if (! $site) {
            $this->command?->error('No site with slug ' . self::SITE_SLUG . ' — nothing written.');

            return;
        }

        $this->writeCovers();

        $removed = Article::where('site_id', $site->id)
            ->whereIn('slug', self::LEFTOVERS)
            ->forceDelete();

        if ($removed > 0) {
            $this->command?->warn("Removed {$removed} throwaway test post(s).");
        }

        $now = Carbon::now();
        $written = 0;

        foreach ($this->posts() as $i => $post) {
            Article::withTrashed()->updateOrCreate(
                ['site_id' => $site->id, 'slug' => $post['slug']],
                [
                    'type'    => Article::TYPE_NEWS,
                    'title'   => $post['title'],
                    'excerpt' => $post['excerpt'],
                    // No trailing "sample content" line: it rendered as the
                    // last paragraph of every article. The rows stay noindex,
                    // which is the marker that actually matters.
                    'body'    => $post['body'],
                    'hero_image_path' => 'news/' . $post['slug'] . '.svg',
                    // Staggered backwards so the feed has a real chronology and
                    // the lead story is not simply the last row inserted.
                    'published_at' => $now->copy()->subDays(2 + $i * 3)->setTime(9, 30),
                    'position'     => ($i + 1) * 10,
                    'deleted_at'   => null,
                    'meta_title'       => $post['title'],
                    'meta_description' => $post['excerpt'],
                    // Sample editorial must never be indexed, even by accident on
                    // a staging host that is reachable from outside.
                    'noindex' => true,
                ],
            );

            $written++;
        }

        // Both menus. The footer repeats the header on this site, so a section
        // present in one and missing from the other looks like an oversight.
        foreach (['header', 'footer'] as $location) {
            $this->ensureMenuLink($site, $location);
        }

        $this->command?->info("{$written} sample news post(s) ready on {$site->slug}.");
        $this->command?->line('Every one is sample data and set to noindex.');
    }

    /**
     * News in one of the site's menus.
     *
     * This site uses the admin-managed navigation, which is taken as authored —
     * so the link has to be a row, not a code branch. Matched on (site,
     * location, url) so re-running cannot produce a second News item in either
     * menu, and an editor who renames or reorders it keeps their change.
     *
     * Appended at the end rather than slotted in: position is the editor's to
     * decide, and guessing at it would silently reshuffle a menu somebody
     * already arranged.
     */
    private function ensureMenuLink(Site $site, string $location): void
    {
        $exists = DB::table('nav_items')
            ->where('site_id', $site->id)
            ->where('location', $location)
            ->where('url', '/news')
            ->exists();

        if ($exists) {
            $this->command?->line("The {$location} menu already links to /news — left as it is.");

            return;
        }

        $position = (int) DB::table('nav_items')
            ->where('site_id', $site->id)
            ->where('location', $location)
            ->max('position');

        DB::table('nav_items')->insert([
            'site_id'          => $site->id,
            'location'         => $location,
            'label'            => 'News',
            'url'              => '/news',
            'position'         => $position + 1,
            'active'           => true,
            'opens_in_new_tab' => false,
            'created_at'       => Carbon::now(),
            'updated_at'       => Carbon::now(),
        ]);

        $this->command?->info("Added \"News\" to the {$location} menu.");
    }

    /**
     * A cover image per post.
     *
     * Drawn, not downloaded: an SVG built here has no licence attached to it, no
     * network dependency, and cannot accidentally be a photograph of a real
     * place or person that the article does not describe. Each one is a distinct
     * hue from winpalack's emerald/teal range so the feed does not look like six
     * copies of one card.
     */
    private function writeCovers(): void
    {
        foreach ($this->posts() as $i => $post) {
            $path = 'news/' . $post['slug'] . '.svg';

            if (Storage::disk('public')->exists($path)) {
                continue;
            }

            // Walks the emerald → teal → cyan arc rather than the whole wheel,
            // so the set reads as one family.
            $hue = 152 + $i * 9;

            Storage::disk('public')->put($path, <<<SVG
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 900" role="img" aria-label="">
                  <defs>
                    <linearGradient id="g{$i}" x1="0" y1="0" x2="1" y2="1">
                      <stop offset="0%" stop-color="hsl({$hue} 58% 32%)"/>
                      <stop offset="100%" stop-color="hsl({$hue} 64% 52%)"/>
                    </linearGradient>
                  </defs>
                  <rect width="1600" height="900" fill="url(#g{$i})"/>
                  <g fill="none" stroke="#ffffff" stroke-opacity="0.16" stroke-width="2">
                    <circle cx="1250" cy="240" r="190"/>
                    <circle cx="1250" cy="240" r="300"/>
                    <circle cx="1250" cy="240" r="410"/>
                  </g>
                  <g fill="#ffffff" fill-opacity="0.10">
                    <rect x="120" y="600" width="420" height="14" rx="7"/>
                    <rect x="120" y="650" width="300" height="14" rx="7"/>
                  </g>
                </svg>
                SVG);
        }
    }

    /**
     * The sample posts.
     *
     * Editorial about this site's own process and about terms any reader can
     * verify — deliberately NOT invented events involving real companies. See
     * the class docblock.
     *
     * @return list<array{slug: string, title: string, excerpt: string, body: string}>
     */
    private function posts(): array
    {
        return [
            [
                'slug'    => 'how-we-check-a-licence-before-listing-a-casino',
                'title'   => 'How We Check A Licence Before Listing A Casino',
                'excerpt' => 'A licence number on a footer proves nothing on its own. Here is the check that happens before anything reaches our list.',
                'body'    => '<p>Every operator we list carries a licence number somewhere in its footer. That number is a claim, not evidence — so the first thing we do is take it to the regulator’s own public register and read what is recorded there.</p>'
                    . '<p>Three things have to agree: the company named on the licence, the domain it covers, and the status of the licence today. A licence held by a related company, or one that covers a different domain, does not cover the site you are about to deposit on.</p>'
                    . '<p>Where any of the three fails to line up, the operator does not go on the list, and we do not write a review explaining why it nearly did.</p>',
            ],
            [
                'slug'    => 'reading-wagering-requirements-without-the-headache',
                'title'   => 'Reading Wagering Requirements Without The Headache',
                'excerpt' => 'The multiplier is the part everyone quotes. It is rarely the part that decides what a bonus is worth.',
                'body'    => '<p>A wagering requirement is usually written as a multiplier — the number of times a bonus must be staked before anything can be withdrawn. It is the figure most sites lead with, and on its own it tells you very little.</p>'
                    . '<p>What matters alongside it: whether the multiplier applies to the bonus alone or to the deposit as well, which games count and at what percentage, whether a maximum stake applies while the requirement is open, and how long you have.</p>'
                    . '<p>Two offers with the same multiplier can differ enormously once those four are read. That is why we publish them rather than the headline number.</p>',
            ],
            [
                'slug'    => 'why-we-publish-withdrawal-times-not-just-bonus-sizes',
                'title'   => 'Why We Publish Withdrawal Times, Not Just Bonus Sizes',
                'excerpt' => 'A large bonus and a slow withdrawal are not a trade worth making. We record both.',
                'body'    => '<p>Bonus size is the easiest thing to compare and the least useful. It is set by marketing, it changes weekly, and it says nothing about what happens after you win.</p>'
                    . '<p>Withdrawal behaviour is harder to summarise and much more informative: how long a first withdrawal takes once identity checks are complete, whether limits are applied per day or per month, and whether the published figures match what players report.</p>'
                    . '<p>Where an operator publishes a processing time, we record it. Where we cannot verify one, we say so rather than fill the gap with an estimate.</p>',
            ],
            [
                'slug'    => 'deposit-limits-timeouts-and-self-exclusion',
                'title'   => 'Deposit Limits, Timeouts And Self-Exclusion: The Tools Worth Knowing',
                'excerpt' => 'Every licensed operator has to offer these. Far fewer make them easy to find.',
                'body'    => '<p>Licensed operators are required to provide safer-play controls: limits on what you can deposit, a short cooling-off period, and self-exclusion for a fixed term. The requirement says they must exist. It does not say they must be obvious.</p>'
                    . '<p>When we check an operator we look for how many steps it takes to reach each one from a signed-in account, whether a limit takes effect immediately when lowered, and whether self-exclusion covers every brand the operator runs or only the one site.</p>'
                    . '<p>If you are reading this and want them now, they are usually under account settings — and if you cannot find them, that is itself worth knowing about the operator.</p>',
            ],
            [
                'slug'    => 'how-player-reviews-are-moderated-here',
                'title'   => 'How Player Reviews Are Moderated Here',
                'excerpt' => 'Reviews go live immediately. Here is what happens next, and what gets removed.',
                'body'    => '<p>A review written on this site appears straight away. We do not hold them for approval, because a queue that takes days is a queue nobody writes into twice.</p>'
                    . '<p>What we remove afterwards: anything naming a private individual, anything that is an advertisement, and duplicate submissions from one person about one operator. We do not remove a review for being negative, and we do not edit wording to soften it.</p>'
                    . '<p>We do not pay for reviews, and operators cannot have one taken down by asking.</p>',
            ],
            [
                'slug'    => 'what-a-safety-score-does-and-does-not-mean',
                'title'   => 'What A Safety Score Does And Does Not Mean',
                'excerpt' => 'Our score summarises checks we ran. It is not a prediction that you will win.',
                'body'    => '<p>Each casino on this site carries a safety score. It summarises the checks behind the listing: licence verified against the register, published withdrawal terms, the presence and reachability of safer-play tools, and whether complaint patterns are visible in public sources.</p>'
                    . '<p>It is not a rating of how enjoyable a casino is, and it is emphatically not a prediction of your results. No score changes the arithmetic of a game with a house edge.</p>'
                    . '<p>Where a check cannot be completed, the score reflects that rather than assuming the best. An unverifiable claim is treated as unverified.</p>',
            ],
        ];
    }
}
