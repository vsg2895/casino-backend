<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Article;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Winpalack's evergreen guides — the real editorial, not sample data.
 *
 * SAFE IN PRODUCTION, unlike the *DemoSeeder classes, and that is the point of
 * it existing: the guides section needs three published articles before it is
 * linked anywhere ({@see Article::MIN_TO_PUBLISH_SECTION}), and retyping three
 * articles into the admin on a fresh host is how two environments end up with
 * different copy under the same URLs.
 *
 * WHAT IT WILL NOT DO
 * -------------------
 * No operator is named, no licence is claimed to have been granted or revoked,
 * and no payout percentage, bonus amount or complaint figure is invented. The
 * worked example in the wagering guide is arithmetic on stated assumptions,
 * which is a demonstration rather than a claim about anybody's terms. Every
 * sentence here is something the site can stand behind.
 *
 * WHAT IT TOUCHES
 * ---------------
 * Only `articles` rows for THIS site whose slug is in GUIDES, matched on
 * (site_id, slug) — never on id, which differs between environments. Nothing is
 * truncated and nothing is deleted.
 *
 * WHAT IT OVERWRITES, AND WHAT IT LEAVES ALONE
 * --------------------------------------------
 * The copy is the seeder's: title, excerpt, body and the two meta fields are
 * rewritten on every run, so re-running is how a correction ships. The editor's
 * decisions are NOT: `published_at`, `position`, `active` and `featured` are set
 * only when the row is first created. Re-running therefore cannot unpublish
 * something, reorder the section, or undo a scheduled date.
 *
 * The one exception is a row that exists with no publish date at all. That is a
 * draft this seeder created and nobody finished, so it gets published rather
 * than left invisible.
 *
 *   php artisan db:seed --class=WinpalackGuidesSeeder --force
 */
class WinpalackGuidesSeeder extends Seeder
{
    private const string SITE_SLUG = 'winpalack';

    public function run(): void
    {
        $site = Site::where('slug', self::SITE_SLUG)->first();

        if (! $site) {
            $this->command?->error('No site with slug ' . self::SITE_SLUG . ' — nothing written.');

            return;
        }

        $created = 0;
        $updated = 0;
        $published = 0;

        foreach ($this->guides() as $i => $guide) {
            // withTrashed: a guide someone deleted and this seeder then
            // "created" again would collide with the (site_id, slug) unique
            // index rather than coming back.
            $article = Article::withTrashed()->firstOrNew([
                'site_id' => $site->id,
                'slug'    => $guide['slug'],
            ]);

            $existed = $article->exists;

            $article->fill([
                'type'             => Article::TYPE_GUIDE,
                'title'            => $guide['title'],
                'excerpt'          => $guide['excerpt'],
                'body'             => $guide['body'],
                'meta_title'       => $guide['title'],
                'meta_description' => $guide['excerpt'],
                // Real editorial, so it is meant to be indexed — the opposite of
                // the demo seeders, which force noindex.
                'noindex'          => false,
            ]);

            $article->deleted_at = null;

            if (! $existed) {
                // Staggered backwards so the section has a real chronology
                // instead of three articles stamped the same minute.
                $article->published_at = Carbon::now()->subDays(4 + $i * 4)->setTime(10, 0);
                $article->position     = ($i + 1) * 10;
                $article->active       = true;
                $article->featured     = false;
            } elseif ($article->published_at === null) {
                // A draft this seeder made that nobody finished. Publishing it
                // is the whole reason the row is here.
                $article->published_at = Carbon::now();
                $published++;
            }

            if ($existed && ! $article->isDirty()) {
                $this->command?->line("  = {$guide['slug']} — already matches");

                continue;
            }

            $article->save();

            if ($existed) {
                $updated++;
                $this->command?->warn("  ~ {$guide['slug']} — copy refreshed");
            } else {
                $created++;
                $this->command?->info("  + {$guide['slug']} — created and published");
            }
        }

        $this->command?->info("Guides on {$site->slug}: {$created} created, {$updated} refreshed.");

        if ($published > 0) {
            $this->command?->info("{$published} existing draft(s) published.");
        }

        $this->report($site);
        $this->refresh($site);
    }

    /**
     * Tell the operator whether this content is actually reachable.
     *
     * Two separate gates decide that, and both live in the admin rather than
     * here: the per-site feature flag, and the three-article minimum the /guides
     * route enforces for itself. A seeder that writes rows and says nothing
     * about either leaves someone staring at a section that will not appear.
     */
    private function report(Site $site): void
    {
        $live = Article::where('site_id', $site->id)
            ->ofType(Article::TYPE_GUIDE)
            ->visible()
            ->count();

        $this->command?->line("{$live} guide(s) now visible to the public API.");

        if (! $site->guides_enabled) {
            $this->command?->warn('Guides are switched OFF for this site. Turn them on in the admin (Sites → ' . $site->slug . ') or /guides stays a 404.');
        }

        if ($live < Article::MIN_TO_PUBLISH_SECTION) {
            $this->command?->warn('The section needs ' . Article::MIN_TO_PUBLISH_SECTION . ' published guides before it is linked in the menu.');
        }
    }

    /**
     * One site only — guides belong to exactly one domain, so there is no
     * fan-out. The same two tag families the admin's own save sends.
     */
    private function refresh(Site $site): void
    {
        SiteCache::flushSite($site->id);

        $tags = ['articles'];

        foreach ($this->guides() as $guide) {
            $tags[] = 'article:' . $guide['slug'];
        }

        RevalidateNextJsSites::dispatch($tags, [$site->id]);
        $this->command?->line('Flushed and revalidated ' . $site->slug . '.');
    }

    /**
     * @return list<array{slug: string, title: string, excerpt: string, body: string}>
     */
    private function guides(): array
    {
        return [
            [
                'slug'    => 'what-wagering-requirements-really-cost',
                'title'   => 'What Wagering Requirements Really Cost',
                'excerpt' => 'The multiplier is the number everyone quotes. Here is what it means once the other four terms are read alongside it.',
                'body'    => <<<'HTML'
                    <p>A wagering requirement is the amount you have to stake before bonus money becomes money you can withdraw. It is written as a multiplier — 30x, 40x — and that single number is what every banner leads with. On its own it tells you very little, because four other terms decide what the multiplier is actually applied to.</p>

                    <h2>What the multiplier multiplies</h2>
                    <p>The first question is whether the requirement runs on the bonus alone or on the deposit and the bonus together. "30x bonus" on a £50 bonus is £1,500 of staking. "30x deposit + bonus" on a £50 deposit matched with £50 is £3,000 — the same headline multiplier, twice the work. Both are common, and only the terms page distinguishes them.</p>

                    <h2>Game weighting</h2>
                    <p>Not every stake counts the same. Slots typically contribute 100%, while table games often contribute a fraction — sometimes 10%, sometimes nothing at all. If blackjack contributes 10%, then £10 wagered moves the requirement by £1. A player who intends to clear a bonus at the tables is doing ten times the turnover they think they are.</p>

                    <h2>Maximum stake while a bonus is active</h2>
                    <p>Most bonuses cap the stake per spin or per hand until the requirement is cleared. Exceed it once and the usual penalty is forfeiture of the bonus and anything won from it. This is the term that most often turns a completed requirement into a refused withdrawal, and it is rarely on the banner.</p>

                    <h2>The clock, and the ceiling</h2>
                    <p>Two limits close the picture. An expiry — commonly somewhere between seven and thirty days — after which whatever is left of the bonus is removed. And a maximum conversion, which caps how much of the winnings can become withdrawable regardless of what you actually won. A cap of 5x the bonus on a £20 bonus means £100 leaves with you and the rest does not.</p>

                    <h2>A worked example</h2>
                    <p>Assume a £50 deposit matched with £50, a 35x requirement on deposit plus bonus, slots at 100%, and a £5 maximum stake. The requirement is £3,500 of turnover. At £1 a spin that is 3,500 spins. Nothing about that is unusual or unfair — it is simply the actual size of the offer, and it is a different proposition from the "£50 free" on the banner.</p>

                    <h2>What to do with this</h2>
                    <p>Read the multiplier, then find the base it applies to, the weighting for the games you actually play, the maximum stake, the expiry and the conversion cap. Five numbers. If any of them is not published, treat that as the answer: an offer whose real cost cannot be calculated before you deposit is one you cannot evaluate.</p>

                    <p>And the arithmetic underneath all of it does not change. Every game listed on this site carries a house edge, bonus or no bonus. A requirement you can clear comfortably is a reason to prefer one offer over another — never a reason to play longer than you meant to.</p>
                    HTML,
            ],
            [
                'slug'    => 'how-to-read-a-licence',
                'title'   => 'How To Read A Licence',
                'excerpt' => 'Where the number lives, which register to check it against, and what a mismatch between company and domain tells you.',
                'body'    => <<<'HTML'
                    <p>Every casino we list carries a licence number, and every licence number can be checked against the register that issued it. The check takes about a minute and it is the single most useful thing a player can do before depositing anywhere — including somewhere we have reviewed.</p>

                    <h2>Where the number lives</h2>
                    <p>Look in the footer of the casino's own site. A licensed operator states the licensing authority, the licence number and the registered company name holding it, usually in small type beside the 18+ mark. An operator that names a regulator without giving a number has told you nothing verifiable, and that distinction matters more than the regulator's reputation.</p>

                    <h2>Check it against the register, not the badge</h2>
                    <p>A logo in a footer is an image. Registers are public and searchable, and the licence is only real if the register says so:</p>
                    <ul>
                      <li>UK Gambling Commission — public register of licensees</li>
                      <li>Malta Gaming Authority — licensee register</li>
                      <li>Curaçao Gaming Control Board — licence lookup</li>
                      <li>Gibraltar Licensing Authority, Isle of Man Gambling Supervision Commission, Kahnawake Gaming Commission — each publishes its own list</li>
                    </ul>
                    <p>Search the number. The entry should name a company, a status, and the domains that company is permitted to operate.</p>

                    <h2>What a mismatch means</h2>
                    <p>Three mismatches are worth stopping for. The company named in the footer is not the company on the register. The domain you are on is not among the domains listed against that licence. Or the status is anything other than active — suspended, surrendered, lapsed.</p>
                    <p>None of these is automatically fraud; group structures are genuinely complicated and registers lag behind changes. But each one means the protection you are relying on has not been confirmed, and the burden is on the operator to make that checkable, not on you to assume it.</p>

                    <h2>What a licence does and does not buy you</h2>
                    <p>A licence means somebody with authority can be complained to, that player funds are subject to rules about segregation, that games have been tested, and that advertising and safer-play obligations apply. Regimes differ in how much of this they enforce, and in how much practical help a player gets from outside that jurisdiction.</p>
                    <p>It does not mean the games favour you, that withdrawals are fast, or that a dispute will be resolved the way you want. It means there is a process. An unlicensed operator offers no process at all, which is the difference that matters.</p>

                    <h2>How we use it</h2>
                    <p>We verify the licence against the register before a casino is listed here, and a listing is removed if the licence lapses. Where a check cannot be completed, the review says so rather than assuming the best — an unverifiable claim is treated as unverified, not as true.</p>
                    HTML,
            ],
            [
                'slug'    => 'withdrawal-limits-worth-checking',
                'title'   => 'Withdrawal Limits Worth Checking',
                'excerpt' => 'Daily and monthly caps decide how long a win takes to reach you. They are rarely on the banner.',
                'body'    => <<<'HTML'
                    <p>Most complaints about slow payouts are not about a casino refusing to pay. They are about limits that were published all along, in a section nobody reads until they have won. Here is what to look for, and when to look for it.</p>

                    <h2>The caps</h2>
                    <p>Withdrawal limits are usually stated per day, per week or per month, and sometimes all three. A monthly cap is the one that bites: a win larger than the cap is not refused, it is paid out in instalments across as many months as it takes. A £10,000 win against a £2,000 monthly limit is five months of waiting, and every one of those payments is correct under the terms.</p>
                    <p>Caps also vary by account tier and by payment method. The number in the general terms is often the lowest one; the figure that applies to you may be better or worse.</p>

                    <h2>The pending period</h2>
                    <p>Between requesting a withdrawal and the money leaving, most casinos hold the request for a stated number of hours. Some allow the request to be reversed during that window and the money returned to your balance — which is a feature presented as convenience and is, in practice, the mechanism by which a withdrawal becomes a further session. If reversal can be disabled on your account, disabling it costs nothing.</p>

                    <h2>Verification, before you need it</h2>
                    <p>Identity verification is a licensing obligation, not an obstacle invented for winners, and it is almost always triggered by the first withdrawal rather than the first deposit. That is why it feels like a delay aimed at payouts. Completing it when you open the account — proof of identity, proof of address, proof of the payment method — moves the wait to a day when nothing is riding on it.</p>

                    <h2>Method, currency and fees</h2>
                    <p>Withdrawals normally have to return to the method used to deposit, so the card or wallet you fund with decides how you are paid. Check the minimum withdrawal, whether the operator charges a fee, and what currency conversion applies if your account is not in the site's base currency. A small fee on a small withdrawal can be a meaningful percentage.</p>

                    <h2>Jackpots and large wins</h2>
                    <p>Progressive jackpots frequently have their own schedule, separate from the standard caps, and large wins may trigger additional source-of-funds checks. Both are normal and both are published. Neither should be a surprise discovered afterwards.</p>

                    <h2>The habit worth forming</h2>
                    <p>Read the withdrawal terms before the first deposit, not after the first win. The information is free, it is public, and it is the same information either way — the only thing that changes is whether you chose the operator knowing it. We publish withdrawal terms in our reviews for exactly this reason, and we would rather a visitor compared them than compared bonus sizes.</p>
                    HTML,
            ],
        ];
    }
}
