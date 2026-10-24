<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\Api\Public\BonusController;
use App\Models\BonusCategory;
use App\Models\Site;
use App\Models\SpecialOffer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Why a site's Bonus area is empty.
 *
 * READ-ONLY. It writes nothing, flushes nothing and sends nothing — it is safe
 * to run on production at any time, which is the whole point of it existing.
 *
 * THE PROBLEM IT SOLVES. The bonus chips render from
 * {@see BonusController::index()}, and that
 * endpoint drops a category the moment it has no visible offer on the site.
 * The front end then renders nothing at all — `sections.length === 0` returns
 * null — so an operator sees a blank band with no error anywhere: not in the
 * browser console, not in laravel.log, not in the API response, which is a
 * perfectly valid `{"data": []}`.
 *
 * SEVEN conditions have to hold at once for one chip to appear, and six of them
 * live on different tables:
 *
 *   1. sites.bonus_enabled            — else the endpoint 404s outright
 *   2. bonus_categories.active
 *   3. special_offers.bonus_category_id is set
 *   4. special_offers.active
 *   5. special_offers.expires_at is null or not past   (scopeClaimable)
 *   6. casino_site.active for THIS site
 *   7. casinos.active
 *
 * Checking those by hand across eleven sites is how the wrong one gets blamed.
 * This walks them in order and prints where the count collapses to zero — the
 * same funnel shape {@see DispatchVerificationPromotions} uses, for the same
 * reason: "0 offers" is not a diagnosis.
 *
 * ONE CAVEAT THE OUTPUT REPEATS. A green funnel here does not prove the site
 * renders chips, because the endpoint's answer is cached in Redis for an hour
 * (`bonus:index:v2:site:<id>:limit:<n>`) and the Next.js page caches it again.
 * Data fixed one minute ago can still be invisible for the rest of the hour.
 */
class DiagnoseBonusArea extends Command
{
    protected $signature = 'bonus:diagnose
                            {site?* : Site slugs to check (default: every active site)}';

    protected $description = 'Explain why a site shows no bonus categories (read-only)';

    public function handle(): int
    {
        $slugs = (array) $this->argument('site');

        $sites = Site::query()
            ->when($slugs !== [], fn ($q) => $q->whereIn('slug', $slugs))
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'bonus_enabled']);

        if ($sites->isEmpty()) {
            $this->error($slugs === []
                ? 'No sites are registered.'
                : 'No site matches: '.implode(', ', $slugs));

            return self::FAILURE;
        }

        // Category-level facts are site-independent — read them once rather
        // than per site, and report them once.
        $categoriesTotal = BonusCategory::query()->count();
        $categoriesActive = BonusCategory::query()->active()->count();

        $this->line('');
        $this->line("Bonus categories: <info>{$categoriesActive}</info> active of {$categoriesTotal}");

        if ($categoriesActive === 0) {
            $this->warn('  No ACTIVE bonus category exists. Every site will be empty until one is');
            $this->warn('  switched on in the admin (Content → Bonus Categories).');
        }

        $this->line('');

        foreach ($sites as $site) {
            $this->diagnose($site);
        }

        $this->line('');
        $this->line('Reminder: fixing the data does not clear the caches.');
        $this->line('  php artisan cache:clear          (or wait out the 1h SiteCache TTL)');
        $this->line('  then re-save any casino in the admin to trigger Next.js revalidation,');
        $this->line('  or redeploy the site so its Data Cache is rebuilt.');

        return self::SUCCESS;
    }

    private function diagnose(Site $site): void
    {
        $this->line("<comment>{$site->slug}</comment> (id {$site->id}) — {$site->name}");

        if (! $site->bonus_enabled) {
            // Nothing below this matters: the endpoint 404s before it runs a
            // single one of those queries.
            $this->line('  <error>bonus_enabled = false</error> — the public endpoint 404s, so the chips');
            $this->line('  never render regardless of the data. Turn the Bonus area on for this');
            $this->line('  site in the admin, then re-run this command.');
            $this->line('');

            return;
        }

        $today = Carbon::now()->toDateString();

        // Each row adds exactly ONE condition to the row above it, so the line
        // where the number collapses names the cause.
        $base = SpecialOffer::query();

        $attached = SpecialOffer::query()
            ->whereHas('casino.sites', fn (Builder $q) => $q->where('sites.id', $site->id));

        $attachedActive = SpecialOffer::query()
            ->casinoAttachedToSite($site->id);

        $casinoActive = (clone $attachedActive)
            ->whereHas('casino', fn (Builder $q) => $q->where('casinos.active', true));

        $offerActive = (clone $casinoActive)->where('special_offers.active', true);

        $claimable = (clone $offerActive)->claimable();

        $categorised = (clone $claimable)->whereNotNull('bonus_category_id');

        $inActiveCategory = (clone $categorised)
            ->whereHas('bonusCategory', fn (Builder $q) => $q->where('active', true));

        $rows = [
            ['offers in the catalogue',        $base->count()],
            ['…on a casino attached here',     $attached->count()],
            ['…attachment active',             $attachedActive->count()],
            ['…casino active',                 $casinoActive->count()],
            ['…offer active',                  $offerActive->count()],
            ["…not expired (as of {$today})",  $claimable->count()],
            ['…filed under a bonus category',  $categorised->count()],
            ['…category is active',            $inActiveCategory->count()],
        ];

        $previous = null;
        $culprit = null;
        $table = [];

        foreach ($rows as [$label, $count]) {
            $marker = '';

            // The FIRST step that drops the count to zero is the answer. Later
            // zero rows are consequences of it, not separate problems.
            if ($count === 0 && $previous !== 0 && $culprit === null) {
                $culprit = $label;
                $marker = '← here';
            }

            $table[] = [$label, $count, $marker];
            $previous = $count;
        }

        // A real table rather than sprintf padding: these labels contain '…',
        // which is three bytes, and `%-34s` pads by BYTES — so the ellipsis
        // rows came out two columns short of the ASCII ones. Symfony measures
        // display width instead, and gets it right without the caller knowing.
        $this->table(['step', 'offers', ''], $table);

        $visibleCategories = BonusCategory::query()
            ->active()
            ->whereHas('specialOffers', function (Builder $q) use ($site): void {
                $q->visibleInBonusArea($site->id);
            })
            ->count();

        $this->line("  <options=bold>CHIPS THIS SITE WILL RENDER: {$visibleCategories}</>");

        if ($visibleCategories > 0) {
            $this->info('  Data is fine. If the site still shows nothing, it is a CACHE — see the note below.');
        } elseif ($culprit !== null) {
            $this->warn("  First failing step: {$culprit}");
        }

        $this->line('');
    }
}
