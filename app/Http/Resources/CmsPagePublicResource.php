<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Site;
use App\Support\Byline;
use App\Support\LegalIdentity;
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
            // Legal-identity tokens resolved against the requesting site; a
            // line whose value is empty is removed, never printed blank.
            'content'          => $this->renderedContent(),
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
            'byline'           => Byline::from(
                $this->relationLoaded('author') ? $this->author : null,
                $this->relationLoaded('reviewer') ? $this->reviewer : null,
                $this->last_reviewed_at,
            ),
        ];
    }

    private function renderedContent(): string
    {
        $site = app()->bound('current_site') ? app('current_site') : $this->site;

        return LegalIdentity::render(
            $this->content,
            $site instanceof Site ? LegalIdentity::valuesFor($site) : [],
        );
    }
}
