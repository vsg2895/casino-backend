<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SpecialOfferVisitResource;
use App\Jobs\ResolveVisitGeoInfo;
use App\Models\SpecialOfferVisit;
use App\Models\SpecialOfferVisitIp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * "Special Offers Visitors Ip Info" — the read side.
 *
 * READ-ONLY BY DESIGN. There is no update and no destroy: this is an audit
 * record, and a screen that can edit what an address was recorded as is worth
 * less than no screen. The one write is re-resolving geography, which replaces
 * a provider's answer and never the observation.
 *
 * Simple-paginated with a separate /count, which is this project's rule for
 * every admin listing — see backend/CLAUDE.md. Both share filtered(), so the
 * number in the badge and the rows in the table can never describe different
 * sets.
 */
class SpecialOfferVisitController extends Controller
{
    private const int PER_PAGE = 50;

    public function index(Request $request): AnonymousResourceCollection
    {
        $visits = $this->filtered($request)
            ->with([
                'site:id,name,slug',
                'specialOffer:id,title,slug,casino_id',
                'specialOffer.casino:id,name',
                'ips',
            ])
            ->latest('special_offer_visits.created_at')
            ->simplePaginate($request->integer('per_page') ?: self::PER_PAGE)
            ->withQueryString();

        return SpecialOfferVisitResource::collection($visits);
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->filtered($request)->count()]);
    }

    public function show(SpecialOfferVisit $specialOfferVisit): SpecialOfferVisitResource
    {
        $specialOfferVisit->load([
            'site:id,name,slug',
            'specialOffer:id,title,slug,casino_id',
            'specialOffer.casino:id,name',
            'ips',
        ]);

        return new SpecialOfferVisitResource($specialOfferVisit);
    }

    /**
     * Headline numbers for the stat tiles.
     *
     * Counted with their own queries rather than derived from the page: the
     * admin conventions are explicit that anything quoting a number must show
     * the count itself, never the paginator's estimate.
     */
    public function stats(Request $request): JsonResponse
    {
        $base = fn (): Builder => $this->filtered($request);

        $today = (clone $base())->whereDate('special_offer_visits.created_at', today())->count();
        $week  = (clone $base())->where('special_offer_visits.created_at', '>=', now()->subDays(7))->count();
        $month = (clone $base())->where('special_offer_visits.created_at', '>=', now()->subDays(30))->count();
        $total = $base()->count();
        $vpn   = (clone $base())->where('vpn_suspected', true)->count();

        $uniqueIps = SpecialOfferVisitIp::whereIn(
            'special_offer_visit_id',
            (clone $base())->select('special_offer_visits.id')
        )->distinct()->count('ip');

        $topCountries = SpecialOfferVisitIp::query()
            ->whereIn('special_offer_visit_id', (clone $base())->select('special_offer_visits.id'))
            ->whereNotNull('country_code')
            ->selectRaw('country_code, COUNT(DISTINCT special_offer_visit_id) as visits')
            ->groupBy('country_code')
            ->orderByDesc('visits')
            ->limit(10)
            ->get()
            ->map(static fn ($row): array => [
                'country_code' => $row->country_code,
                'visits'       => (int) $row->visits,
            ]);

        return response()->json([
            'visits_today'     => $today,
            'visits_7d'        => $week,
            'visits_30d'       => $month,
            'visits_total'     => $total,
            'unique_ips'       => $uniqueIps,
            'vpn_suspected'    => $vpn,
            // Integer percent of the FILTERED set, not of all time — the tiles
            // must describe the same rows the table below them shows.
            'vpn_percent'      => $total > 0 ? (int) round($vpn / $total * 100) : 0,
            'top_countries'    => $topCountries,
        ]);
    }

    /**
     * Re-run geography for the given visits.
     *
     * Clears `geo_resolved_at` first, because the job skips an address that
     * has already been attempted — without this the dispatch would be a
     * no-op and the button would appear broken.
     */
    public function resolveGeo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $ids = SpecialOfferVisit::whereIn('id', $validated['ids'])->pluck('id');

        SpecialOfferVisitIp::whereIn('special_offer_visit_id', $ids)
            ->update(['geo_resolved_at' => null]);

        foreach ($ids as $id) {
            ResolveVisitGeoInfo::dispatch($id);
        }

        return response()->json(['queued' => $ids->count()]);
    }

    /**
     * ONE builder for the listing, the count and the stats.
     *
     * @return Builder<SpecialOfferVisit>
     */
    private function filtered(Request $request): Builder
    {
        $query = SpecialOfferVisit::query();

        if ($from = $request->query('date_from')) {
            $query->whereDate('special_offer_visits.created_at', '>=', $from);
        }

        if ($to = $request->query('date_to')) {
            $query->whereDate('special_offer_visits.created_at', '<=', $to);
        }

        if ($offerId = $request->integer('special_offer_id')) {
            $query->where('special_offer_id', $offerId);
        }

        if ($siteId = $request->integer('site_id')) {
            $query->where('site_id', $siteId);
        }

        if ($casinoId = $request->integer('casino_id')) {
            $query->whereHas('specialOffer', static fn (Builder $o): Builder => $o->where('casino_id', $casinoId));
        }

        if ($request->boolean('mismatch_only')) {
            $query->where('ip_mismatch', true);
        }

        if ($request->boolean('vpn_only')) {
            $query->where('vpn_suspected', true);
        }

        if ($request->boolean('has_ipv6')) {
            $query->whereHas('ips', static fn (Builder $ip): Builder => $ip->where('ip_version', 6));
        }

        if ($country = $request->query('country_code')) {
            $query->whereHas('ips', static fn (Builder $ip): Builder => $ip->where('country_code', strtoupper((string) $country)));
        }

        if ($type = $request->query('ip_type')) {
            $query->whereHas('ips', static fn (Builder $ip): Builder => $ip->where('type', $type));
        }

        /*
         * IP SEARCH ACROSS EVERY TYPE, which is the point of the second table.
         * Searching `primary_ip` alone would miss an address that only ever
         * appeared in an X-Forwarded-For chain or as a client probe — exactly
         * the addresses an operator is looking for when they search at all.
         */
        if ($search = trim((string) $request->query('ip', ''))) {
            $query->whereHas('ips', static fn (Builder $ip): Builder => $ip->where('ip', 'like', "%{$search}%"));
        }

        return $query;
    }
}
