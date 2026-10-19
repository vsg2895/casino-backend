<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-facing CMS page resource. Never exposes draft status or internal ids —
 * only published content needed to render the page + SEO meta.
 */
class CmsPagePublicResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug'             => $this->slug,
            'title'            => $this->title,
            'content'          => $this->content,
            'meta_title'       => $this->meta_title ?? $this->title,
            'meta_description' => $this->meta_description,
            /*
             * Whether this page asks to stay out of the index.
             *
             * The site used to decide this from a hardcoded list of the eleven
             * standard slugs, so /about and the other four trust pages were
             * suppressed alongside the six legal ones, and changing it needed a
             * deploy. Now it is per page, per site, editable in the admin — and
             * a page becomes indexable the moment someone rewrites it for this
             * site rather than leaving it as the shared template.
             */
            'noindex'          => (bool) $this->noindex,
            // ISO-8601 STRING, not the Carbon instance. These responses are cached
            // via SiteCache, and a serialized Carbon returns as
            // __PHP_Incomplete_Class — so the cached copy shipped a broken object
            // where the uncached one shipped a date. Same wire format either way.
            'updated_at'       => $this->updated_at?->toISOString(),
        ];
    }
}
