<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Site;
use App\Support\Seo\SeoResolver;
use App\Services\SiteContentResolver;
use App\Support\ResolvedSiteContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpecialOfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $content = $this->siteContent();

        return [
            'id'                 => $this->id,
            'casino_id'          => $this->casino_id,
            'bonus_category_id' => $this->bonus_category_id,

            /*
             * The bonus type's SLUG, not just its id.
             *
             * The front end decides which bonuses carry a "Claim" call to
             * action from the type they are filed under, and an id cannot
             * express that rule: ids differ between a developer's database and
             * production, so a hardcoded one would silently pick the wrong
             * category — or none — on deploy. The slug is generated once and
             * never regenerated (BonusCategory::getSlugOptions), so it is the
             * stable name for the type even after an editor renames it.
             *
             * Resolved through the relationship rather than a join so an offer
             * with no type simply returns null.
             */
            'bonus_category_slug' => $this->bonusCategory?->slug,

            /*
             * The type's NAME, for the label a reader actually sees.
             *
             * The slug above is a machine value — the front end matches on it to
             * decide which bonuses carry a Claim button — and deriving a label
             * from it ("no-deposit-offers" → "No Deposit Offers") would be a
             * guess that goes wrong the moment an editor renames the type.
             * Resolved through the same already-loaded relation, so it costs
             * nothing beyond the slug.
             */
            'bonus_category_name' => $this->bonusCategory?->name,
            'casino'             => new CasinoResource($this->whenLoaded('casino')),
            'title'              => $this->title,
            'slug'               => $this->slug,
            'image_path'         => $this->image_path,
            'banner_image'       => $this->banner_image,
            'bonuses'            => $this->bonuses,
            'affiliate_url'      => $this->affiliate_url,
            // As THIS site publishes it: its own override where it has one,
            // else the default content — see SiteContentResolver.
            'description'        => $content?->description ?? $this->description,
            /*
             * Finished meta for the offer page. ADDITIVE keys. On a public
             * request they are resolved per site and never empty: this site's
             * value, else the default, else "{title} – {casino}" and a plain-
             * text snippet of the description. In the admin they are the raw
             * default values the edit form saves.
             */
            'meta_title'         => $content !== null ? $content->metaTitle : $this->meta_title,
            'meta_description'   => $content !== null ? $content->metaDescription : $this->meta_description,
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
            'seo'        => $this->seoBlock($content),
            /*
             * Whether this offer's page may be indexed — true unless an admin
             * set noindex (SpecialOffer::isIndexable). Computed server-side so
             * the robots tag and the sitemap cannot disagree about it.
             */
            // Resolved per site on a public request (SiteContentResolver).
            'is_indexable' => $content !== null ? ! $content->noindex : $this->resource->isIndexable(),
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
    private function seoBlock(?ResolvedSiteContent $content): array
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
                // Same key in both branches, so the shape never varies by caller.
                'description_from_record' => trim((string) $this->meta_description) !== '',
            ];
        }

        /** @var Site $site */
        $site = app('current_site');

        $resolved = app(SeoResolver::class)->resolve(
            $site,
            'special_offer',
            ['name' => (string) $this->title],
            // What a person typed (this site's, else the default) — never the
            // generated fallback, which must not outrank the site's pattern.
            $content?->storedMetaTitle,
            $content?->storedMetaDescription,
        );

        return [
            ...$resolved,
            'canonical_url' => $this->canonical_url,
            'noindex'       => $content?->noindex ?? (bool) $this->noindex,
            /*
             * WHERE the resolved description came from.
             *
             * `SeoResolver` returns a finished string whether it came from this
             * record's own `meta_description` or from the site's pattern, and
             * the front end needs to tell them apart: a pattern is one sentence
             * with the title substituted in, so it reads the same on every
             * offer, while an override was typed for this offer alone.
             *
             * Derived here rather than added to SeoResolver's return shape, so
             * the casino resource — which spreads the same array and has no use
             * for it — is untouched.
             */
            'description_from_record' => trim((string) $content?->storedMetaDescription) !== '',
        ];
    }

    /**
     * This offer's content for the site being served, or null outside a
     * public request (the admin edits the default content and must see it).
     */
    private function siteContent(): ?ResolvedSiteContent
    {
        if (! app()->bound('current_site')) {
            return null;
        }

        return app(SiteContentResolver::class)->offer(app('current_site'), $this->resource);
    }
}
