<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Site;
use App\Support\Seo\SeoResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-facing casino resource.
 * Built from a join so pivot columns (affiliate_url, position, featured) are available
 * directly on the model instance. The per-site affiliate_url overrides the casino default.
 */
class CasinoWithAttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'name'                  => $this->name,
            'slug'                  => $this->slug,
            'image_path'            => $this->image_path,
            'banner_image'          => $this->banner_image,
            'bonuses'               => $this->bonuses,
            'description'           => $this->description,
            'rating'                => (int) $this->rating,
            'meta_title'            => $this->meta_title,
            'meta_description'      => $this->meta_description,
            'categories'            => CategoryResource::collection($this->whenLoaded('categories')),
            // Which countries this casino serves. Additive: the relation was
            // already modelled and admin-editable, and was reaching the admin
            // resource but not this one, so no public page could link a casino
            // to the /countries hub it already has.
            //
            // whenLoaded, so a caller that does not eager-load it pays nothing
            // and the key is simply absent rather than triggering N+1 queries.
            'countries'             => CountryResource::collection($this->whenLoaded('countries')),
            // The factual profile. Absent rather than empty when nothing has
            // been filled in: an all-null object would force the front end to
            // decide what "empty" means, and it would eventually decide wrong.
            'detail'                => $this->when(
                $this->relationLoaded('detail') && $this->detail !== null && ! $this->detail->isEmpty(),
                fn () => (new CasinoDetailResource($this->detail))->resolve(),
            ),
            'special_offers'        => SpecialOfferResource::collection($this->whenLoaded('specialOffers')),
            'featured_special_offer' => new SpecialOfferResource($this->whenLoaded('featuredSpecialOffer')),
            // ISO-8601 STRING, not the Carbon instance. These responses are cached
            // via SiteCache, and a serialized Carbon returns as
            // __PHP_Incomplete_Class — so the cached copy shipped a broken object
            // where the uncached one shipped a date. Same wire format either way.
            // Resolved SEO. ADDITIVE — `meta_title` and `meta_description` above
            // are untouched and still mean exactly what they always meant, so the
            // five sites that read them keep working unchanged. A site that wants
            // stored patterns and per-record overrides reads this block instead.
            // Drives the bonus sub-page. Null means that page is not published.
            'bonuses_intro'         => $this->bonuses_intro,
            // The editorial review date. Null means nobody recorded one, and
            // the front end then says nothing rather than falling back to
            // updated_at and implying a person checked it.
            'reviewed_at'           => $this->reviewed_at?->toDateString(),
            'seo'                   => $this->seoBlock(),
            'updated_at'            => $this->updated_at?->toISOString(),
            'attachment'            => [
                'affiliate_url' => $this->affiliate_url,
                'position'      => (int) $this->position,
                'featured'      => (bool) $this->featured,
            ],
        ];
    }

    /**
     * Title, description, canonical and noindex, already resolved.
     *
     * Resolution happens here rather than on the front end so the substitution
     * rules live in one place; the client never sees a pattern or a token.
     *
     * `title`/`description` are null when neither an override nor a site pattern
     * exists, which the front end reads as "use my own wording".
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
            'casino',
            ['name' => (string) $this->name],
            $this->meta_title,
            $this->meta_description,
        );

        return [
            ...$resolved,
            'canonical_url' => $this->canonical_url,
            'noindex'       => (bool) $this->noindex,
        ];
    }
}
