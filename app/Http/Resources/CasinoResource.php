<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing casino resource (full record, including attached relations when loaded).
 */
class CasinoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'name'                      => $this->name,
            'slug'                      => $this->slug,
            'image_path'                => $this->image_path,
            'banner_image'              => $this->banner_image,
            'bonuses'                   => $this->bonuses,
            'affiliate_url'             => $this->affiliate_url,
            'description'               => $this->description,
            'rating'                    => (int) $this->rating,
            'sort_order'                => (int) $this->sort_order,
            'featured_special_offer_id' => $this->featured_special_offer_id,
            'meta_title'                => $this->meta_title,
            'meta_description'          => $this->meta_description,
            'active'                    => (bool) $this->active,
            'category_ids'              => $this->whenLoaded('categories', fn () => $this->categories->pluck('id')),
            'categories'                => CategoryResource::collection($this->whenLoaded('categories')),
            'special_offers'            => SpecialOfferResource::collection($this->whenLoaded('specialOffers')),
            'sites'                     => $this->whenLoaded('sites', fn () => $this->sites->map(fn (Site $site) => [
                'site_id'       => $site->id,
                'site_name'     => $site->name,
                'site_slug'     => $site->slug,
                'site_domain'   => $site->domain,
                // Public base URL for building shareable links. Derived from the
                // site's Next.js revalidation_url origin (the local dev host while
                // developing, the live host in prod); falls back to the domain.
                'site_url'      => self::publicBaseUrl($site),
                'affiliate_url' => $site->pivot->affiliate_url,
                'position'      => (int) $site->pivot->position,
                'featured'      => (bool) $site->pivot->featured,
                'active'        => (bool) $site->pivot->active,
            ])),
            'created_at'                => $this->created_at,
            'updated_at'                => $this->updated_at,
        ];
    }

    /** Origin (scheme://host[:port]) of the site's Next.js app, or https://{domain}. */
    private static function publicBaseUrl(Site $site): string
    {
        $parts = $site->revalidation_url ? parse_url($site->revalidation_url) : false;

        if ($parts === false || empty($parts['host'])) {
            return 'https://' . $site->domain;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return "{$scheme}://{$parts['host']}{$port}";
    }
}
