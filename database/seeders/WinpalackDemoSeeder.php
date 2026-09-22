<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BonusCategory;
use App\Models\Casino;
use App\Models\CasinoDetail;
use App\Models\CasinoReview;
use App\Models\Country;
use App\Models\FacetConfig;
use App\Models\ForumArticle;
use App\Models\Site;
use App\Models\SpecialOffer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fills every winpalack section with SAMPLE data so each fetch can be exercised.
 *
 * ── Read this before changing the operator-profile part ─────────────────────
 *
 * The casinos attached to this site are REAL BRANDS — BitStarz, PlayAmo,
 * RocketPlay. `operator_profile_enabled` is on, so licences, withdrawal times
 * and payment methods render publicly as statements of fact about those
 * businesses.
 *
 * So every value this seeder writes is VISIBLY sample data: licences read
 * "SAMPLE LICENCE — not verified", companies read "Sample Holdings Ltd", times
 * read "Sample — verify before publishing". That exercises the fetch, renders
 * the section and fills the admin screens, while making it impossible for a
 * seeded value to be mistaken for a checked fact if this ever reaches a real
 * domain.
 *
 * Plausible-looking invented figures would test exactly the same code paths and
 * would be indistinguishable from verified data the moment anyone looked at the
 * page. That is the one thing worth avoiding here, so it is avoided.
 *
 * ── Safety ──────────────────────────────────────────────────────────────────
 *
 * Refuses to run in production. Idempotent: every write is an updateOrCreate or
 * a firstOrCreate keyed on something stable, so re-running changes nothing.
 * NOTHING is deleted — it only fills gaps, so existing real content survives.
 *
 *   php artisan db:seed --class=WinpalackDemoSeeder
 */
class WinpalackDemoSeeder extends Seeder
{
    private const SAMPLE = 'Sample — verify before publishing';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Refusing to run in production — this writes sample data.');

            return;
        }

        $site = Site::query()->where('slug', 'winpalack')->first();

        if ($site === null) {
            $this->command?->error('No winpalack site found.');

            return;
        }

        $this->command?->info('Filling winpalack sample data…');

        $this->features($site);
        $this->facets($site);
        $this->operatorProfiles($site);
        $this->countries($site);
        $this->offers($site);
        $this->bonusCategories();
        $this->reviews($site);
        $this->forumSeed($site);

        $this->command?->info('Done. Re-run `php artisan search:reindex` to refresh site search.');
    }

    /**
     * Switch on every optional surface.
     *
     * `byline_enabled` needs an author to be meaningful, so the two are set
     * together — turning the flag on with a null name renders an empty byline.
     */
    private function features(Site $site): void
    {
        $site->forceFill([
            'countries_enabled'        => true,
            'reviews_enabled'          => true,
            'guides_enabled'           => true,
            'news_enabled'             => true,
            'bonus_enabled'            => true,
            'forum_enabled'            => true,
            'operator_profile_enabled' => true,
            'byline_enabled'           => true,
            'author_name'              => $site->author_name ?? 'Winpalack Editorial',
            // Role and bio stay empty: both render as sublines under the
            // editorial heading, where placeholder copy reads as a broken page.
            'author_role'              => $site->author_role,
            'author_bio'               => $site->author_bio,
        ])->save();

        $this->command?->line('  features: all switches on');
    }

    /**
     * Casino filter facets.
     *
     * The table was EMPTY, which is why the filter rail had nothing in it — the
     * facet keys are code, but which ones a site shows is data.
     */
    private function facets(Site $site): void
    {
        foreach (FacetConfig::FACETS as $i => $facet) {
            FacetConfig::query()->updateOrCreate(
                ['site_id' => $site->id, 'facet' => $facet],
                [
                    'label'    => FacetConfig::DEFAULT_LABELS[$facet] ?? ucfirst($facet),
                    'position' => $i,
                    'active'   => true,
                ],
            );
        }

        $this->command?->line('  facets: ' . count(FacetConfig::FACETS) . ' configured');
    }

    /**
     * Operator profiles for every attached casino.
     *
     * See the class docblock: every string here announces itself as sample data
     * rather than imitating a verified fact about a real operator.
     */
    private function operatorProfiles(Site $site): void
    {
        $casinos = $this->attachedCasinos($site);
        $filled = 0;

        foreach ($casinos as $casino) {
            // firstOrCreate, not updateOrCreate: a profile someone actually
            // researched must never be overwritten by sample text.
            $detail = CasinoDetail::query()->firstOrNew(['casino_id' => $casino->id]);

            if ($detail->exists) {
                continue;
            }

            $detail->fill([
                'established_year' => null,
                'company'          => 'Sample Holdings Ltd (sample data)',
                'licences'         => ['SAMPLE LICENCE — not verified'],
                'currencies'       => ['EUR', 'USD', 'CAD'],
                'payment_methods'  => ['Sample Card', 'Sample Wallet', 'Sample Crypto'],
                'min_deposit'      => self::SAMPLE,
                'min_withdrawal'   => self::SAMPLE,
                'withdrawal_limit' => self::SAMPLE,
                'pending_time'     => self::SAMPLE,
                'withdrawal_time'  => self::SAMPLE,
                'verification_speed' => self::SAMPLE,
                'deposit_fees'     => false,
                'withdrawal_fees'  => false,
                'game_providers'   => ['Sample Studios', 'Sample Gaming', 'Sample Live'],
                'rng_tested'       => false,
                'progressive_jackpots' => false,
                'live_chat'        => true,
                'email_support'    => true,
                'support_email'    => 'support@example.test',
                'support_languages' => ['English'],
                // The responsible-gambling tools default to TRUE, because the
                // section exists to help players find them and a sample profile
                // claiming none would render a misleading empty state.
                'tool_deposit_limit'   => true,
                'tool_loss_limit'      => true,
                'tool_session_limit'   => true,
                'tool_reality_check'   => true,
                'tool_withdrawal_lock' => true,
                'tool_self_exclusion'  => true,
            ])->save();

            $filled++;
        }

        $this->command?->line("  operator profiles: {$filled} filled, " . ($casinos->count() - $filled) . ' left alone');
    }

    /**
     * Attach each casino to a spread of countries.
     *
     * Enough that /countries and every country page has something, without
     * touching a casino that already carries the Worldwide wildcard — that is a
     * deliberate editorial choice and re-attaching individual countries would
     * contradict it.
     */
    private function countries(Site $site): void
    {
        $worldwideId = Country::worldwideId();
        $pool = Country::query()
            ->when($worldwideId !== null, fn ($q) => $q->whereKeyNot($worldwideId))
            ->orderBy('id')
            ->limit(24)
            ->pluck('id')
            ->all();

        if ($pool === []) {
            return;
        }

        $attached = 0;

        foreach ($this->attachedCasinos($site) as $i => $casino) {
            $existing = DB::table('casino_country')->where('casino_id', $casino->id)->pluck('country_id')->all();

            if ($worldwideId !== null && in_array($worldwideId, $existing, true)) {
                continue;   // already Worldwide — leave it alone
            }

            // A rotating window, so the casinos do not all share one country set
            // and the per-country pages differ from each other.
            $slice = array_slice($pool, ($i * 5) % max(1, count($pool) - 8), 8);

            foreach ($slice as $countryId) {
                if (in_array($countryId, $existing, true)) {
                    continue;
                }

                DB::table('casino_country')->insertOrIgnore([
                    'casino_id'  => $casino->id,
                    'country_id' => $countryId,
                ]);
                $attached++;
            }
        }

        $this->command?->line("  countries: {$attached} new casino-country links");
    }


    /**
     * Give every attached casino its own offers.
     *
     * All eleven existing offers belonged to ONE casino, so six casino pages
     * rendered an empty offers block and the per-casino /bonuses sub-page 404'd
     * for all of them. Each casino gets enough to clear that page's threshold.
     *
     * `bonuses_intro` is set at the same time and for the same reason: the
     * sub-page requires 2+ live offers AND admin-written intro copy, so seeding
     * one without the other still leaves a 404 and looks like a bug.
     */
    private function offers(Site $site): void
    {
        $created = 0;
        $intros = 0;

        foreach ($this->attachedCasinos($site) as $casino) {
            $have = SpecialOffer::query()->where('casino_id', $casino->id)->where('active', true)->count();

            for ($i = $have; $i < 3; $i++) {
                $n = $i + 1;
                SpecialOffer::query()->create([
                    'casino_id'   => $casino->id,
                    'title'       => "Sample Offer {$n} — {$casino->name}",
                    'slug'        => \Illuminate\Support\Str::slug("sample offer {$n} {$casino->slug}"),
                    // No invented figure. A bonus amount is the single most
                    // load-bearing claim on an affiliate page, and a seeded
                    // "200% up to EUR 500" is indistinguishable from a real one.
                    'bonuses'     => null,
                    'description' => 'Sample offer created by WinpalackDemoSeeder so the offers listing, '
                        . 'the offer detail page, the Bonus sections and the per-casino bonuses page all render. '
                        . 'The terms below are placeholders, not this operator\'s actual offer.',
                    'affiliate_url' => 'https://example.test/sample-offer',
                    'wagering_requirement' => self::SAMPLE,
                    'min_deposit' => self::SAMPLE,
                    'max_cashout' => self::SAMPLE,
                    'terms_url'   => 'https://example.test/sample-terms',
                    'sort_order'  => $n,
                    'active'      => true,
                ]);
                $created++;
            }

            // Only where the page can actually qualify.
            if ($casino->bonuses_intro === null || trim((string) $casino->bonuses_intro) === '') {
                $casino->forceFill([
                    'bonuses_intro' => 'Sample intro created by WinpalackDemoSeeder so this operator\'s bonuses '
                        . 'page meets its publication threshold. Replace with real editorial copy before publishing.',
                ])->save();
                $intros++;
            }
        }

        $this->command?->line("  offers: {$created} created, {$intros} bonuses intros filled");
    }

    /** A wider spread of bonus categories so the Bonus menu has real sub-sections. */
    private function bonusCategories(): void
    {
        $wanted = [
            // No descriptions: they render under the category heading on the
            // home page and the offers listing, where placeholder copy shows.
            'Welcome Bonuses'   => null,
            'No Deposit Offers' => null,
            'Cashback'          => null,
            'Reload Bonuses'    => null,
        ];

        $position = BonusCategory::query()->max('position') ?? 0;
        $created = 0;

        foreach ($wanted as $name => $description) {
            $category = BonusCategory::query()->firstOrCreate(
                ['slug' => \Illuminate\Support\Str::slug($name)],
                ['name' => $name, 'description' => $description, 'position' => ++$position, 'active' => true],
            );

            if ($category->wasRecentlyCreated) {
                $created++;
            }
        }

        // Spread the existing offers across the categories, so every bonus
        // sub-section renders something instead of one holding all of them.
        $categoryIds = BonusCategory::query()->where('active', true)->orderBy('position')->pluck('id')->all();
        $offers = SpecialOffer::query()->orderBy('id')->get();

        foreach ($offers as $i => $offer) {
            if ($offer->bonus_category_id !== null) {
                continue;
            }

            $offer->forceFill(['bonus_category_id' => $categoryIds[$i % count($categoryIds)]])->save();
        }

        $this->command?->line("  bonus categories: {$created} created, offers spread across " . count($categoryIds));
    }

    /**
     * Player reviews.
     *
     * Marked as sample in the body and attributed to "Sample Player N" — a
     * fabricated review attributed to a plausible human name is a fake review,
     * which is a different thing from test data and is not worth creating even
     * locally.
     */
    private function reviews(Site $site): void
    {
        $created = 0;

        foreach ($this->attachedCasinos($site) as $casino) {
            $have = CasinoReview::query()
                ->where('site_id', $site->id)
                ->where('casino_id', $casino->id)
                ->count();

            for ($i = $have; $i < 4; $i++) {
                CasinoReview::query()->create([
                    'site_id'      => $site->id,
                    'casino_id'    => $casino->id,
                    'author_name'  => 'Sample Player ' . ($i + 1),
                    'author_email' => 'sample.player.' . ($i + 1) . '@example.test',
                    'rating'       => 3 + ($i % 3),
                    'title'        => 'Sample review ' . ($i + 1),
                    'body'         => 'Sample review text created by WinpalackDemoSeeder so the reviews feed, '
                        . 'the per-casino review block and the moderation queue all have something to render. '
                        . 'This is not a real player’s account of using this casino.',
                    'status'       => $i === 3 ? 'pending' : 'published',
                    'published_at' => $i === 3 ? null : now()->subDays($i * 3),
                ]);
                $created++;
            }
        }

        $this->command?->line("  reviews: {$created} created");
    }

    /** A couple of forum discussions, so /forum is not just the volume seed. */
    private function forumSeed(Site $site): void
    {
        $category = \App\Models\ForumCategory::query()->where('site_id', $site->id)->orderBy('id')->first();

        if ($category === null) {
            $this->command?->line('  forum: no boards yet — run ForumVolumeSeeder or create one in the admin');

            return;
        }

        $topics = [
            'How long did your withdrawal actually take?' => 'Sample discussion opened by WinpalackDemoSeeder.',
            'Which bonus terms are worth reading twice?'  => 'Sample discussion opened by WinpalackDemoSeeder.',
        ];

        $created = 0;

        foreach ($topics as $title => $body) {
            $article = ForumArticle::query()->firstOrCreate(
                ['site_id' => $site->id, 'slug' => \Illuminate\Support\Str::slug($title)],
                [
                    'forum_category_id' => $category->id,
                    'title'   => $title,
                    'excerpt' => $body,
                    'body'    => "<p>{$body}</p>",
                    'status'  => ForumArticle::STATUS_PUBLISHED,
                    'pinned'  => $created === 0,
                ],
            );

            if ($article->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->command?->line("  forum: {$created} discussions created");
    }

    /** @return \Illuminate\Support\Collection<int, Casino> */
    private function attachedCasinos(Site $site): \Illuminate\Support\Collection
    {
        return Casino::query()
            ->whereHas('sites', fn ($q) => $q->where('sites.id', $site->id))
            ->orderBy('id')
            ->get();
    }
}
