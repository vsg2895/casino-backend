<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SpecialOfferVisit;
use App\Models\SpecialOfferVisitIp;
use App\Services\IpInfo\IpInfoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Attaches geography to every public address of one visit.
 *
 * ON `low`, because nobody is waiting: the visitor has already been served the
 * page and the admin reads this table minutes or days later. Putting it on
 * `high` would let a burst of bonus-page traffic delay welcome and verify
 * email, which are the two things in this platform a human IS waiting for.
 *
 * NEVER FAILS THE VISIT. Every provider error is swallowed inside
 * IpInfoService, and this job treats "no geography" as an ordinary outcome: a
 * row with addresses and no country is a correct record of what we know, and
 * is far better than no row at all.
 */
class ResolveVisitGeoInfo implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'low';

    /**
     * Three attempts with a widening gap: a provider blip is worth retrying,
     * a quota wall is not worth retrying forever.
     */
    public int $tries = 3;

    /** @return list<int> */
    public array $backoff = [60, 300];

    /**
     * Well below the `low` queue's retry_after (1200s on redis). A visit holds
     * a handful of addresses and each lookup is capped at 5s, so this is an
     * upper bound with a wide margin, not an expected duration.
     */
    public int $timeout = 120;

    public function __construct(public readonly int $visitId)
    {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(): void
    {
        $service = IpInfoService::make();

        $visit = SpecialOfferVisit::with('ips')->find($this->visitId);

        if ($visit === null) {
            return;
        }

        // Nothing configured: the addresses are already stored, so there is no
        // work to do and no reason to burn two retries discovering that.
        if (! $service->isEnabled()) {
            $visit->refreshDerivedFlags();

            return;
        }

        foreach ($visit->ips as $row) {
            $this->resolveRow($service, $row);
        }

        // After the writes, not before: the flags are a function of the geo
        // the rows now carry (a hosting-range verdict sets vpn_suspected).
        $visit->refresh()->refreshDerivedFlags();
    }

    private function resolveRow(IpInfoService $service, SpecialOfferVisitIp $row): void
    {
        // Private ranges and mDNS names are unresolvable by construction, and
        // asking about one is a billed request guaranteed to return nothing.
        if ($row->is_private || $row->is_mdns) {
            return;
        }

        // Already attempted. Re-resolving is an explicit admin action, which
        // clears geo_resolved_at first; without this guard a retry of the job
        // would pay for every address again.
        if ($row->geo_resolved_at !== null) {
            return;
        }

        $geo = $service->lookup($row->ip);

        if ($geo === null) {
            // Mark the attempt even on failure, or every later job retries the
            // same dead address. The flags stay as they were.
            $row->forceFill(['geo_resolved_at' => now()])->save();

            return;
        }

        $row->forceFill(array_filter([
            'country_code' => $geo['country_code'],
            'country'      => $geo['country'],
            'region'       => $geo['region'],
            'city'         => $geo['city'],
            'latitude'     => $geo['latitude'],
            'longitude'    => $geo['longitude'],
            'timezone'     => $geo['timezone'],
            'isp'          => $geo['isp'],
            'org'          => $geo['org'],
            'asn'          => $geo['asn'],
        ], static fn ($v): bool => $v !== null) + [
            'geo_raw'         => $geo['raw'],
            'geo_resolved_at' => now(),
        ])->save();

        /*
         * The privacy flags are written SEPARATELY and only when the provider
         * actually said something. A free ipinfo plan omits the `privacy`
         * object entirely, and writing `false` there would have the admin
         * assert "not a VPN" on the strength of data it never received.
         */
        foreach (['is_proxy', 'is_vpn', 'is_hosting'] as $flag) {
            if ($geo[$flag] !== null) {
                $row->forceFill([$flag => $geo[$flag]])->save();
            }
        }
    }
}
