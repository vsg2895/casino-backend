<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ResolveVisitGeoInfo;
use App\Models\Site;
use App\Models\SpecialOffer;
use App\Models\SpecialOfferVisit;
use App\Models\SpecialOfferVisitIp;
use App\Support\UserAgentParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Writes the visit rows. The controllers above this are two thin shells.
 *
 * TWO PHASES, and the split is the design rather than an optimisation. Phase 1
 * runs from the request and always succeeds; phase 2 adds what the page could
 * find out about its own connection and is allowed to never arrive. Anything
 * that depended on phase 2 completing would lose the majority of visits to ad
 * blockers, closed tabs and timeouts.
 */
final class SpecialOfferVisitRecorder
{
    /**
     * How long two hits from the same address on the same offer count as one
     * visit.
     *
     * A LITERAL, not config: raising it merges genuinely separate visits and
     * lowering it inflates every count, and either silently changes what the
     * numbers in the admin mean. Thirty minutes is the ordinary analytics
     * session window — long enough to cover a reload or a back-button return,
     * short enough that a revisit after lunch is its own row.
     */
    private const int DEDUP_MINUTES = 30;

    public function __construct(private readonly VisitorIpResolver $ips) {}

    /**
     * Phase 1. Returns null when the visit must not be recorded at all —
     * a bot, or an offer that is not a special offer.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(Request $request, SpecialOffer $offer, Site $site, array $payload): ?SpecialOfferVisit
    {
        $userAgent = $request->userAgent();

        // THE OFFER GATE. Uses the model's own rule so this can never drift
        // from the public listing — see SpecialOffer::isSpecialOffer().
        if (! $offer->isSpecialOffer()) {
            return null;
        }

        $agent = UserAgentParser::parse($userAgent);

        if ($agent['is_bot']) {
            return null;
        }

        $resolved = $this->ips->resolve($request);

        return DB::transaction(function () use ($resolved, $offer, $site, $payload, $userAgent, $agent, $request): SpecialOfferVisit {
            $visit = $this->findRecentVisit($resolved['primary'], $offer->id)
                ?? new SpecialOfferVisit(['visit_uuid' => $payload['visit_uuid']]);

            $visit->fill([
                'special_offer_id' => $offer->id,
                'site_id'          => $site->id,
                'primary_ip'       => $resolved['primary'],
                'headers_raw'      => $resolved['headers_raw'],
                'user_agent'       => $userAgent,
                'referer'          => $payload['referer'] ?? $request->header('Referer'),
                'page_url'         => $payload['page_url'] ?? null,
                'accept_language'  => $request->header('Accept-Language'),
                'browser_timezone' => $payload['timezone'] ?? null,
                'screen_size'      => $payload['screen'] ?? null,
                'platform'         => $payload['platform'] ?? null,
                'device_type'      => $agent['device_type'],
                'browser'          => $agent['browser'],
                'os'               => $agent['os'],
                'consent'          => $payload['consent'] ?? null,
            ])->save();

            $this->storeRows($visit, $resolved['rows']);

            // Cloudflare's country, written straight onto the primary row so
            // the admin has a country immediately — before any queue runs, and
            // even when no paid provider is configured at all.
            if ($resolved['country_hint'] !== null && $resolved['primary'] !== null) {
                SpecialOfferVisitIp::where('special_offer_visit_id', $visit->id)
                    ->where('ip', $resolved['primary'])
                    ->whereNull('country_code')
                    ->update(['country_code' => $resolved['country_hint']]);
            }

            $visit->refresh()->refreshDerivedFlags();

            return $visit;
        });
    }

    /**
     * Phase 2. Idempotent: beacons retry, and a repeat must not duplicate rows
     * or re-trigger a round of paid lookups.
     *
     * @param  array<string, mixed>  $payload
     */
    public function addClientIps(SpecialOfferVisit $visit, array $payload): SpecialOfferVisit
    {
        $rows = [];

        foreach (['client_ipv4' => 4, 'client_ipv6' => 6] as $key => $expectedVersion) {
            $value = $payload[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $classified = SpecialOfferVisitIp::classify($value);

            // The family must match the probe that produced it. An "IPv4
            // endpoint" returning an IPv6 address means the value did not come
            // from where the client says it did, so it is not stored.
            if ($classified === null || $classified['ip_version'] !== $expectedVersion) {
                continue;
            }

            $rows[] = $classified + ['type' => $key, 'source' => 'client_reported'];
        }

        if ($rows !== []) {
            DB::transaction(function () use ($visit, $rows): void {
                $this->storeRows($visit, $rows);
            });

            $visit->refresh()->refreshDerivedFlags();
            ResolveVisitGeoInfo::dispatch($visit->id);
        }

        return $visit;
    }

    /** Queue geography for a visit, unless the visitor did not consent to it. */
    public function dispatchGeo(SpecialOfferVisit $visit): void
    {
        if ($visit->consent !== 'all') {
            return;
        }

        ResolveVisitGeoInfo::dispatch($visit->id);
    }

    /**
     * An existing visit for the same address and offer inside the window.
     *
     * Null primary_ip never dedups: "we could not establish an address" is not
     * an identity, and matching on it would collapse every unidentifiable
     * visitor in the window into a single row.
     */
    private function findRecentVisit(?string $primaryIp, int $offerId): ?SpecialOfferVisit
    {
        if ($primaryIp === null) {
            return null;
        }

        return SpecialOfferVisit::where('primary_ip', $primaryIp)
            ->where('special_offer_id', $offerId)
            ->where('created_at', '>=', now()->subMinutes(self::DEDUP_MINUTES))
            ->latest('id')
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function storeRows(SpecialOfferVisit $visit, array $rows): void
    {
        foreach ($rows as $row) {
            // The table's unique key is (visit, ip, type), so a repeat of the
            // same claim updates in place instead of raising. The geo columns
            // are NOT in the update set — a re-post must never wipe a resolved
            // country back to null.
            SpecialOfferVisitIp::updateOrCreate(
                [
                    'special_offer_visit_id' => $visit->id,
                    'ip'                     => $row['ip'],
                    'type'                   => $row['type'],
                ],
                [
                    'ip_version' => $row['ip_version'],
                    'is_private' => $row['is_private'],
                    'is_mdns'    => $row['is_mdns'],
                    'source'     => $row['source'],
                ]
            );
        }
    }
}
