<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Redirect;
use App\Models\SpecialOffer;
use App\Support\SiteCache;

/**
 * Keeps an offer's OLD address working after its slug moves.
 *
 * A special offer's slug follows its title (see {@see SpecialOffer::booted()}),
 * which is what an editor expects: rename "2nd Deposit Bonus (Copy)" to "3rd
 * Deposit Bonus" and the link should say so. The cost of that convenience is
 * that every URL ever shared, indexed or pasted into a chat points at an
 * address that no longer resolves — unless something writes it down. This is
 * that something.
 *
 * For every site the offer appears on it:
 *
 *  1. writes a 301 from the old path to the new one;
 *  2. RE-POINTS any redirect that already led to the old path, so a second
 *     rename does not leave A → B → 404. This is the part that is easy to miss
 *     and only shows up the second time an offer is renamed;
 *  3. REMOVES a rule whose source is the new path, which would otherwise
 *     shadow the live page with a redirect away from it.
 *
 * Rules are per site because `redirects` is a per-site table and the front end
 * matches them in middleware on every request — see
 * {@see \App\Http\Controllers\Api\Public\RedirectController}.
 */
final class OfferSlugRedirectService
{
    /** The public route an offer lives at, on every site. */
    private const string PATH_PREFIX = '/special-offers/';

    /**
     * Record a slug change. Returns the number of sites a rule was written for.
     *
     * @param  list<int>  $siteIds  Sites the offer is on — before AND after the
     *                              save, since a casino can be re-attached in
     *                              the same request.
     */
    public function record(SpecialOffer $offer, string $previousSlug, array $siteIds): int
    {
        $newSlug = (string) $offer->slug;
        $previousSlug = trim($previousSlug);

        if ($previousSlug === '' || $previousSlug === $newSlug) {
            return 0;
        }

        $siteIds = array_values(array_unique(array_filter($siteIds)));

        if ($siteIds === []) {
            return 0;
        }

        $from = self::PATH_PREFIX . $previousSlug;
        $to = self::PATH_PREFIX . $newSlug;

        foreach ($siteIds as $siteId) {
            $this->writeRule($siteId, $from, $to);
        }

        // The rules are cached per site and read by middleware on every page
        // view, so a new one is useless until both caches are told.
        foreach ($siteIds as $siteId) {
            SiteCache::flushSite($siteId);
        }

        RevalidateNextJsSites::dispatch(['redirects'], $siteIds);

        return count($siteIds);
    }

    private function writeRule(int $siteId, string $from, string $to): void
    {
        // Normalised exactly as the model does on write, so "already exists"
        // means the same thing here as it does in the unique index.
        $from = Redirect::normalisePath($from);
        $to = Redirect::normalisePath($to);

        // A rule pointing AWAY from the address the offer now occupies would
        // redirect visitors off a live page.
        Redirect::query()->where('site_id', $siteId)->where('source_path', $to)->delete();

        // Earlier renames: A → B becomes A → C when B becomes C. Without this a
        // second rename leaves the oldest links redirecting to a 404.
        Redirect::query()
            ->where('site_id', $siteId)
            ->where('destination_path', $from)
            ->update(['destination_path' => $to]);

        Redirect::updateOrCreate(
            ['site_id' => $siteId, 'source_path' => $from],
            ['destination_path' => $to, 'status_code' => 301, 'active' => true],
        );
    }
}
