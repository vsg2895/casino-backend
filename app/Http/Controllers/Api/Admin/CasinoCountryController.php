<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\InvalidateCasinoCache;
use App\Models\Casino;
use App\Models\Country;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Whole-list operations on the casino ↔ country pivot.
 *
 * The per-casino country picker on the casino form is the right tool for one
 * operator. These two endpoints are the blunt instrument for the other case:
 * putting every casino in every market at once, or clearing the lot.
 *
 * A DEDICATED PREFIX, not nested under `casinos`. These act on the whole table
 * rather than on a `{casino}`, and a literal segment sharing that prefix is
 * exactly what the project's route-ordering rule warns about.
 *
 * WHAT AN ATTACHMENT MEANS: it puts the casino on `/countries/<slug>` — it tells
 * a visitor this operator accepts players from there. `attachAll` therefore
 * makes that claim for every operator in every seeded market at once. The
 * endpoint does it because it was asked for; the judgement about whether the
 * claim is true belongs to whoever presses the button.
 */
class CasinoCountryController extends Controller
{
    /**
     * Attach every ACTIVE casino to every ACTIVE country.
     *
     * One `INSERT … SELECT` across the cross join rather than N×M model writes:
     * with 8 casinos and 79 countries that is 632 rows, and a loop would be 632
     * round trips. `insertOrIgnore` leans on the composite primary key, so this
     * is idempotent — rows that already exist are skipped, not duplicated.
     */
    public function attachAll(): JsonResponse
    {
        $casinoIds = Casino::where('active', true)->pluck('id');
        $countryIds = Country::where('active', true)->pluck('id');

        if ($casinoIds->isEmpty() || $countryIds->isEmpty()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Need at least one active casino and one active country.',
            ], 422);
        }

        $before = DB::table('casino_country')->count();

        $rows = [];
        foreach ($casinoIds as $casinoId) {
            foreach ($countryIds as $countryId) {
                $rows[] = ['casino_id' => (int) $casinoId, 'country_id' => (int) $countryId];
            }
        }

        // Chunked: a single INSERT with tens of thousands of tuples can exceed
        // max_allowed_packet on a modest MySQL, and this table grows as
        // casinos × countries.
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('casino_country')->insertOrIgnore($chunk);
        }

        $after = DB::table('casino_country')->count();

        $this->refreshAffectedSites();

        return response()->json([
            'ok'       => true,
            'casinos'  => $casinoIds->count(),
            'countries' => $countryIds->count(),
            'attached' => $after - $before,
            'total'    => $after,
            'message'  => sprintf(
                '%d attachment(s) added — %d casino(s) x %d country/countries.',
                $after - $before,
                $casinoIds->count(),
                $countryIds->count(),
            ),
        ]);
    }

    /**
     * Remove EVERY casino ↔ country attachment.
     *
     * Requires `confirm: true` in the body. This clears hand-curated market
     * lists as readily as bulk-attached ones and there is no undo, so it must
     * not be reachable by a stray POST or a mis-wired button.
     */
    public function detachAll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'confirm' => ['required', 'accepted'],
        ], [
            'confirm.required' => 'Send confirm=true — this removes every casino/country attachment.',
            'confirm.accepted' => 'Send confirm=true — this removes every casino/country attachment.',
        ]);

        $removed = DB::table('casino_country')->count();

        DB::table('casino_country')->delete();

        $this->refreshAffectedSites();

        return response()->json([
            'ok'      => true,
            'removed' => $removed,
            'message' => "{$removed} attachment(s) removed. No casino is attached to any country now.",
        ]);
    }

    /**
     * Flush and revalidate every site that publishes any casino.
     *
     * Not scoped to "affected" sites the way a single save is: both operations
     * touch the whole table, so every site's country pages are stale. Without
     * this they keep serving the old lists for up to an hour.
     */
    private function refreshAffectedSites(): void
    {
        $siteIds = DB::table('casino_site')
            ->distinct()
            ->pluck('site_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($siteIds === []) {
            return;
        }

        foreach ($siteIds as $siteId) {
            SiteCache::flushSite($siteId);
        }

        InvalidateCasinoCache::dispatch($siteIds, ['countries', 'casinos']);
    }
}
