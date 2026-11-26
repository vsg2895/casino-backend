<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreSpecialOfferVisitClientIpsRequest;
use App\Http\Requests\Public\StoreSpecialOfferVisitRequest;
use App\Models\Site;
use App\Models\SpecialOffer;
use App\Models\SpecialOfferVisit;
use App\Services\SpecialOfferVisitRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Visitor tracking for Special Offer pages.
 *
 * Inside the verify.site group, so the site is resolved from X-Site-Key and
 * never from the body — a client-supplied site id would let one site's key
 * write visits onto another's records.
 *
 * ANSWERS 204 FOR EVERYTHING IT DECLINES TO STORE. A bot, an offer that is
 * not a special offer, an offer this site does not publish: all of them get
 * the same empty success. The alternative — 404 for an unknown offer, 200 for
 * a tracked one — turns this endpoint into an oracle that enumerates which
 * offers exist and which are special, for anyone holding a site key.
 */
class SpecialOfferVisitController extends Controller
{
    public function __construct(private readonly SpecialOfferVisitRecorder $recorder) {}

    /** Phase 1 — written from the request itself. */
    public function store(StoreSpecialOfferVisitRequest $request): Response|JsonResponse
    {
        /** @var Site $site */
        $site = $request->get('_site');

        $offer = SpecialOffer::with('bonusCategory')
            // Scoped to what this site actually publishes, through the pivot.
            ->casinoAttachedToSite($site->id)
            ->find($request->integer('special_offer_id'));

        if ($offer === null) {
            return response()->noContent();
        }

        $visit = $this->recorder->record($request, $offer, $site, $request->validated());

        if ($visit === null) {
            return response()->noContent();
        }

        $this->recorder->dispatchGeo($visit);

        // The uuid back, so phase 2 can address the row. Nothing else: the
        // visitor has no business reading what we resolved about them, and an
        // id would expose the table's size.
        return response()->json(['visit_uuid' => $visit->visit_uuid], 201);
    }

    /**
     * Phase 2 — what the page could establish about its own connection.
     *
     * Addressed by uuid rather than id, and scoped to the calling site, so one
     * site's key cannot append addresses to another site's visit.
     */
    public function storeClientIps(
        StoreSpecialOfferVisitClientIpsRequest $request,
        string $site,
        string $visitUuid,
    ): Response {
        /** @var Site $resolvedSite */
        $resolvedSite = $request->get('_site');

        $visit = SpecialOfferVisit::where('visit_uuid', $visitUuid)
            ->where('site_id', $resolvedSite->id)
            ->first();

        // 204 for a missing visit too: phase 2 routinely arrives for a visit
        // phase 1 declined to store (a bot that runs scripts, an offer that is
        // not special), and that is an ordinary outcome, not a client error.
        if ($visit === null) {
            return response()->noContent();
        }

        if ($visit->consent !== 'all') {
            return response()->noContent();
        }

        $this->recorder->addClientIps($visit, $request->validated());

        return response()->noContent();
    }

    /**
     * The address this server sees for the caller.
     *
     * Served per family by DNS, not by code: point `ipv4.<domain>` at an A
     * record only and `ipv6.<domain>` at an AAAA record only, and the browser
     * can reach at most the families it actually has. Asking the same question
     * twice over two single-family names is the whole mechanism — there is no
     * way for this endpoint to "force" IPv6 on a visitor who has none, and it
     * does not try.
     *
     * Returns the resolved client address, which behind a trusted proxy is the
     * forwarded one and otherwise the peer. Nothing is stored here.
     */
    public function ip(\Illuminate\Http\Request $request): JsonResponse
    {
        $resolved = app(\App\Services\VisitorIpResolver::class)->resolve($request);

        return response()->json([
            'ip'      => $resolved['primary'],
            'version' => $resolved['primary'] !== null
                ? \App\Models\SpecialOfferVisitIp::classify($resolved['primary'])['ip_version'] ?? null
                : null,
        ]);
    }
}
