<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Site;
use App\Support\Seo\SeoResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpecialOfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'casino_id'          => $this->casino_id,
            'casino'             => new CasinoResource($this->whenLoaded('casino')),
            'title'              => $this->title,
            'slug'               => $this->slug,
            'image_path'         => $this->image_path,
            'banner_image'       => $this->banner_image,
            'bonuses'            => $this->bonuses,
            'affiliate_url'      => $this->affiliate_url,
            'description'        => $this->description,
            'rating'             => (int) $this->rating,
            'sort_order'         => (int) $this->sort_order,
            'active'             => (bool) $this->active,
            // ISO-8601 STRING, not the Carbon instance. These responses are cached
            // via SiteCache, and a serialized Carbon returns as
            // __PHP_Incomplete_Class — so the cached copy shipped a broken object
            // where the uncached one shipped a date. Same wire format either way.
            'created_at'         => $this->created_at?->toISOString(),
            'updated_at'         => $this->updated_at?->toISOString(),
            // Resolved SEO — additive, see CasinoWithAttachmentResource for
            // why the existing fields are left exactly as they are.
            // Structured bonus terms. Additive: `bonuses` above is untouched
            // and remains the scannable headline every site already renders.
            'terms'      => [
                'wagering_requirement' => $this->wagering_requirement,
                'min_deposit'          => $this->min_deposit,
                'max_cashout'          => $this->max_cashout,
                'bonus_code'           => $this->bonus_code,
                'terms_url'            => $this->terms_url,
                'expires_at'           => $this->expires_at?->toDateString(),
                // Resolved server-side so every site agrees on what "expired"
                // means rather than each one comparing dates in its own timezone.
                'expired'              => $this->hasExpired(),
            ],
            'seo'        => $this->seoBlock(),
        ];
    }

    /**
     * Title, description, canonical and noindex, already resolved.
     *
     * Offers are rendered in a site context on every public path, so the bound
     * site is always available here.
     *
     * @return array<string, mixed>
     */
    private function seoBlock(): array
    {
        // This resource is reachable from the ADMIN as well as the public API,
        // and `current_site` is bound only by the public site-key middleware.
        // Without the guard, opening the admin list would fatal.
        if (! app()->bound('current_site')) {
            return [
                'title'         => null,
                'description'   => null,
                'canonical_url' => $this->canonical_url,
                'noindex'       => (bool) $this->noindex,
            ];
        }

        /** @var Site $site */
        $site = app('current_site');

        $resolved = app(SeoResolver::class)->resolve(
            $site,
            'special_offer',
            ['name' => (string) $this->title],
            $this->meta_title ?? null,
            $this->meta_description ?? null,
        );

        return [
            ...$resolved,
            'canonical_url' => $this->canonical_url,
            'noindex'       => (bool) $this->noindex,
        ];
    }
}
