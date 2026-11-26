<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One visit.
 *
 * The list and the detail view share this resource; the heavier fields are
 * gated on the route so a page of 50 rows does not carry 50 raw header blobs.
 */
class SpecialOfferVisitResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = $request->routeIs('*.show');
        $primary = $this->primaryIpRow();

        return [
            'id'               => $this->id,
            'visit_uuid'       => $this->visit_uuid,
            'created_at'       => $this->created_at?->toIso8601String(),

            'special_offer_id' => $this->special_offer_id,
            'special_offer'    => $this->whenLoaded('specialOffer', fn (): array => [
                'id'    => $this->specialOffer->id,
                'title' => $this->specialOffer->title,
                'slug'  => $this->specialOffer->slug,
                'casino' => $this->specialOffer->relationLoaded('casino') && $this->specialOffer->casino !== null
                    ? ['id' => $this->specialOffer->casino->id, 'name' => $this->specialOffer->casino->name]
                    : null,
            ]),

            'site'             => $this->whenLoaded('site', fn (): array => [
                'id'   => $this->site->id,
                'name' => $this->site->name,
                'slug' => $this->site->slug,
            ]),

            'primary_ip'       => $this->primary_ip,

            // Flattened onto the row so the list can show a country and an ISP
            // without the client walking the ips relation for every row.
            'country_code'     => $primary?->country_code,
            'country'          => $primary?->country,
            'city'             => $primary?->city,
            'isp'              => $primary?->isp,

            'ip_mismatch'      => $this->ip_mismatch,
            'vpn_suspected'    => $this->vpn_suspected,
            'is_proxy'         => $primary?->is_proxy ?? false,
            'is_hosting'       => $primary?->is_hosting ?? false,
            'unique_ip_count'  => $this->unique_ip_count,

            // The two client probes, pulled out for their own columns.
            'client_ipv4'      => $this->typedIp('client_ipv4'),
            'client_ipv6'      => $this->typedIp('client_ipv6'),

            'device_type'      => $this->device_type,
            'browser'          => $this->browser,
            'os'               => $this->os,
            'consent'          => $this->consent,

            // Soft signal: the browser's own zone against the one its address
            // geolocates to. Null means one of the two is unknown, which the
            // client must render differently from "they agree".
            'timezone_mismatch' => $this->timezoneMismatch(),

            'page_url'         => $this->when($detail, $this->page_url),
            'referer'          => $this->when($detail, $this->referer),
            'user_agent'       => $this->when($detail, $this->user_agent),
            'accept_language'  => $this->when($detail, $this->accept_language),
            'browser_timezone' => $this->when($detail, $this->browser_timezone),
            'screen_size'      => $this->when($detail, $this->screen_size),
            'platform'         => $this->when($detail, $this->platform),
            'headers_raw'      => $this->when($detail, $this->headers_raw),

            'ips'              => SpecialOfferVisitIpResource::collection($this->whenLoaded('ips')),
        ];
    }

    private function typedIp(string $type): ?string
    {
        if (! $this->relationLoaded('ips')) {
            return null;
        }

        return $this->ips->firstWhere('type', $type)?->ip;
    }
}
