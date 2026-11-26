<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One address of one visit, with everything the admin needs to judge how much
 * to believe it.
 *
 * `source` ships to the client as-is so the table can badge a client-reported
 * value rather than presenting it alongside a server-seen one as equivalent.
 */
class SpecialOfferVisitIpResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'ip'              => $this->ip,
            'ip_version'      => $this->ip_version,
            'is_private'      => $this->is_private,
            'is_mdns'         => $this->is_mdns,
            'type'            => $this->type,
            'source'          => $this->source,

            'country_code'    => $this->country_code,
            'country'         => $this->country,
            'region'          => $this->region,
            'city'            => $this->city,
            'latitude'        => $this->latitude,
            'longitude'       => $this->longitude,
            'timezone'        => $this->timezone,
            'isp'             => $this->isp,
            'org'             => $this->org,
            'asn'             => $this->asn,
            'is_proxy'        => $this->is_proxy,
            'is_vpn'          => $this->is_vpn,
            'is_hosting'      => $this->is_hosting,
            'geo_resolved_at' => $this->geo_resolved_at?->toIso8601String(),

            // Only on the detail view: the provider's whole payload is large
            // and the list renders none of it.
            'geo_raw'         => $this->when($request->routeIs('*.show'), $this->geo_raw),
        ];
    }
}
