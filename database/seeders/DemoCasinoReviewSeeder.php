<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Casino;
use App\Models\CasinoReview;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SAMPLE reviews, so the forum page can be judged with content in it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS IS DEMO DATA AND MUST NEVER RUN IN PRODUCTION.
 *
 * These rows are attributed to people who do not exist, about real, named
 * gambling operators. Published to real visitors they would be fake reviews —
 * deceptive on their face, and unlawful in most of the markets these sites are
 * read in (FTC endorsement rules, UK CMA, EU UCPD).
 *
 * Two things make that impossible by accident rather than by discipline:
 *
 *   1. run() ABORTS when the app environment is `production`.
 *   2. every row is addressed `@example.invalid` — a reserved TLD that can
 *      never resolve — so the rows are trivially identifiable and are what
 *      `purge()` deletes.
 *
 * Remove them with:  php artisan db:seed --class=DemoCasinoReviewSeeder -- --purge
 * or from tinker:    (new Database\Seeders\DemoCasinoReviewSeeder)->purge();
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The spread is deliberate rather than uniform, because the point is to exercise
 * the LAYOUT: one casino with more reviews than the page previews (so "Read all
 * N" appears), several with a handful, two with exactly one (so the singular
 * wording is visible), reviews with and without a headline, short and long
 * bodies, a paragraph break, ratings from 2 to 5, and dates spread over two
 * months so the "last activity" ordering is actually visible.
 *
 * It also seeds PENDING and HIDDEN rows. Those must not appear on the page —
 * they are here so that is demonstrable rather than assumed.
 */
class DemoCasinoReviewSeeder extends Seeder
{
    /** The marker domain. Reserved by RFC 2606: it can never be a real address. */
    private const string MARKER_DOMAIN = '@example.invalid';

    private const string SITE_SLUG = 'winpalack';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Refusing to seed demo reviews in production.');

            return;
        }

        if (in_array('--purge', (array) ($_SERVER['argv'] ?? []), true)) {
            $this->purge();

            return;
        }

        $site = Site::where('slug', self::SITE_SLUG)->first();

        if (! $site) {
            $this->command?->warn('Site "' . self::SITE_SLUG . '" not found — nothing seeded.');

            return;
        }

        $casinos = Casino::query()
            ->where('active', true)
            ->whereHas('sites', fn ($q) => $q->where('sites.id', $site->id)->where('casino_site.active', true))
            ->orderBy('id')
            ->get(['id', 'name'])
            ->keyBy('name');

        if ($casinos->isEmpty()) {
            $this->command?->warn('No casinos are published on ' . self::SITE_SLUG . ' — nothing seeded.');

            return;
        }

        $purged = $this->purge(quiet: true);
        $created = 0;

        DB::transaction(function () use ($site, $casinos, &$created): void {
            foreach ($this->reviews() as $row) {
                $casino = $casinos->get($row['casino']);

                // A demo row for a casino this site does not publish is skipped
                // rather than guessed at — it would be invisible anyway.
                if ($casino === null) {
                    continue;
                }

                $publishedAt = Carbon::now()->subDays($row['days_ago'])->setTime(9 + ($created % 9), ($created * 7) % 60);

                CasinoReview::create([
                    'site_id'      => $site->id,
                    'casino_id'    => $casino->id,
                    'author_name'  => $row['author'],
                    'author_email' => strtolower(str_replace(' ', '.', $row['author'])) . self::MARKER_DOMAIN,
                    'rating'       => $row['rating'],
                    'title'        => $row['title'],
                    'body'         => $row['body'],
                    'status'       => $row['status'],
                    // Only a published review carries a live date; the others
                    // are exactly the states the public feed must exclude.
                    'published_at' => $row['status'] === CasinoReview::STATUS_PUBLISHED ? $publishedAt : null,
                    'created_at'   => $publishedAt,
                    'updated_at'   => $publishedAt,
                ]);

                $created++;
            }
        });

        SiteCache::flushSite($site->id);

        $published = collect($this->reviews())->where('status', CasinoReview::STATUS_PUBLISHED)->count();

        $this->command?->info(sprintf(
            'Seeded %d demo reviews (%d published, %d held back) on %s%s.',
            $created,
            $published,
            $created - $published,
            self::SITE_SLUG,
            $purged > 0 ? " — replaced {$purged} from a previous run" : '',
        ));
        $this->command?->warn('DEMO DATA. Remove with: php artisan db:seed --class=DemoCasinoReviewSeeder -- --purge');
    }

    /**
     * Delete every row this seeder created, and nothing else.
     *
     * Keyed on the marker domain, so a real review can never be caught by it.
     */
    public function purge(bool $quiet = false): int
    {
        $deleted = CasinoReview::where('author_email', 'like', '%' . self::MARKER_DOMAIN)->delete();

        if (! $quiet) {
            $site = Site::where('slug', self::SITE_SLUG)->first();

            if ($site) {
                SiteCache::flushSite($site->id);
            }

            $this->command?->info("Removed {$deleted} demo reviews.");
        }

        return $deleted;
    }

    /**
     * The sample content.
     *
     * Written to describe an EXPERIENCE — signing up, finding the terms, waiting
     * on support — and never to assert a checkable fact about an operator. No
     * payout times, no licence claims, no bonus figures: those are data, and
     * inventing them about a named business is a different thing from filling a
     * layout.
     *
     * @return list<array{casino:string,author:string,rating:int,title:?string,body:string,status:string,days_ago:int}>
     */
    private function reviews(): array
    {
        $pub = CasinoReview::STATUS_PUBLISHED;

        return [
            // BitStarz — more than the page previews, so "Read all N" shows.
            ['casino' => 'BitStarz', 'author' => 'Marcus T', 'rating' => 5, 'days_ago' => 2,
                'title' => 'Verification was painless for once',
                'body' => "Uploaded my documents on a Sunday evening and the account was cleared before I'd finished reading the bonus terms.\n\nWhat I appreciated most is that the wagering requirement was stated on the offer itself rather than buried three pages deep. That alone puts it ahead of most places I've tried.",
                'status' => $pub],
            ['casino' => 'BitStarz', 'author' => 'Priya N', 'rating' => 4, 'days_ago' => 9,
                'title' => 'Good, with one gripe',
                'body' => 'The live tables are well run and I have had no trouble with the cashier. My only complaint is that the promotional emails come a little too often for my taste — easy enough to turn off, but I had to go looking for the setting.',
                'status' => $pub],
            ['casino' => 'BitStarz', 'author' => 'Dan R', 'rating' => 5, 'days_ago' => 17,
                'title' => null,
                'body' => 'Been using this one for a few months now. Support answers in the chat rather than sending you to a form, which is rarer than it should be.',
                'status' => $pub],
            ['casino' => 'BitStarz', 'author' => 'Elena V', 'rating' => 3, 'days_ago' => 26,
                'title' => 'Fine, not remarkable',
                'body' => 'Everything worked as advertised. The interface is a bit busy on mobile and I lost my place in the game list a couple of times, but nothing that stopped me playing.',
                'status' => $pub],
            ['casino' => 'BitStarz', 'author' => 'Tom H', 'rating' => 4, 'days_ago' => 38,
                'title' => 'Clear terms',
                'body' => 'Signed up mainly because the terms page was actually readable. Still is.',
                'status' => $pub],

            // WooCasino — a second thread with several.
            ['casino' => 'WooCasino', 'author' => 'Sofia K', 'rating' => 5, 'days_ago' => 4,
                'title' => 'Support actually read my message',
                'body' => 'Had a question about a pending withdrawal and got a straight answer from a person, not a script. They explained what was outstanding and it was resolved the same day.',
                'status' => $pub],
            ['casino' => 'WooCasino', 'author' => 'James O', 'rating' => 4, 'days_ago' => 12,
                'title' => null,
                'body' => 'Straightforward sign-up, no surprises in the small print. The game search could be better — filtering by provider is buried.',
                'status' => $pub],
            ['casino' => 'WooCasino', 'author' => 'Aisha B', 'rating' => 5, 'days_ago' => 21,
                'title' => 'The responsible play tools are real',
                'body' => "Deposit limits are where you'd expect them and they take effect immediately rather than after a cooling-off period, which is how it should be. I set mine on day one and have not had to think about it since.",
                'status' => $pub],
            ['casino' => 'WooCasino', 'author' => 'Nikolai P', 'rating' => 2, 'days_ago' => 33,
                'title' => 'Not for me',
                'body' => 'Nothing went wrong exactly, but the bonus I claimed had conditions I misread and that was on me as much as them. Leaving two stars because the wording could have been plainer at the point of claiming.',
                'status' => $pub],

            // RocketPlay
            ['casino' => 'RocketPlay', 'author' => 'Hannah L', 'rating' => 5, 'days_ago' => 6,
                'title' => 'Smooth on a phone',
                'body' => 'Most of these sites are clearly designed for a desktop and then squashed. This one works properly on a phone, which is where I actually play.',
                'status' => $pub],
            ['casino' => 'RocketPlay', 'author' => 'Ben C', 'rating' => 4, 'days_ago' => 15,
                'title' => null,
                'body' => 'No complaints. Account setup took a few minutes and the withdrawal process was explained up front.',
                'status' => $pub],
            ['casino' => 'RocketPlay', 'author' => 'Yuki M', 'rating' => 4, 'days_ago' => 29,
                'title' => 'Reasonable all round',
                'body' => 'Decent selection, sensible layout, and the promotions do not chase you around the site. That is worth more than another welcome offer.',
                'status' => $pub],

            // 7 Bit Casino
            ['casino' => '7 Bit Casino', 'author' => 'Grace W', 'rating' => 5, 'days_ago' => 8,
                'title' => 'Easy to find what I needed',
                'body' => 'The terms, the limits and the contact details are all one click from the footer. Sounds small, but it is the first thing I check now.',
                'status' => $pub],
            ['casino' => '7 Bit Casino', 'author' => 'Omar S', 'rating' => 3, 'days_ago' => 23,
                'title' => null,
                'body' => 'Perfectly usable. The account page could show pending transactions more clearly — I was not sure whether something had gone through.',
                'status' => $pub],

            // Luckydreams — exactly one, so the singular wording is visible.
            ['casino' => 'Luckydreams', 'author' => 'Isabel F', 'rating' => 4, 'days_ago' => 11,
                'title' => 'Solid first impression',
                'body' => 'Only been playing a couple of weeks so I will keep this short — sign-up was quick, the welcome terms were clear, and nothing has surprised me yet.',
                'status' => $pub],

            // PlayAmo — one, and a recent one, so it heads the ordering.
            ['casino' => 'PlayAmo', 'author' => 'Viktor D', 'rating' => 5, 'days_ago' => 1,
                'title' => 'Withdrawal went through without a fuss',
                'body' => 'Requested a withdrawal, was asked for one document I had not yet supplied, sent it, and it was processed. No chasing, no repeated requests for the same file.',
                'status' => $pub],

            // Win Spirit
            ['casino' => 'Win Spirit', 'author' => 'Chloe A', 'rating' => 4, 'days_ago' => 19,
                'title' => null,
                'body' => 'Good range of tables and the live chat is staffed at the hours I actually play. Interface takes a little getting used to.',
                'status' => $pub],
            ['casino' => 'Win Spirit', 'author' => 'Rashid J', 'rating' => 5, 'days_ago' => 31,
                'title' => 'No pressure tactics',
                'body' => 'What I notice by its absence: no countdown timers, no pop-ups telling me an offer is about to expire. It treats you like an adult.',
                'status' => $pub],

            // ── Held back on purpose ────────────────────────────────────────
            // These must NOT reach the page. They exist so that is provable.
            ['casino' => 'BitStarz', 'author' => 'Pending Submitter', 'rating' => 1, 'days_ago' => 1,
                'title' => 'This one is awaiting moderation',
                'body' => 'If you can read this on the public forum, the pending filter is broken.',
                'status' => CasinoReview::STATUS_PENDING],
            ['casino' => 'WooCasino', 'author' => 'Another Pending', 'rating' => 5, 'days_ago' => 3,
                'title' => null,
                'body' => 'Also awaiting moderation. Also must not appear publicly.',
                'status' => CasinoReview::STATUS_PENDING],
            ['casino' => 'RocketPlay', 'author' => 'Hidden Submitter', 'rating' => 1, 'days_ago' => 5,
                'title' => 'This one was rejected',
                'body' => 'Hidden by a moderator. If this is visible on the forum, the status filter is broken.',
                'status' => CasinoReview::STATUS_HIDDEN],
        ];
    }
}
