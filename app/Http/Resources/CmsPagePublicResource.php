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
            // ISO-8601 STRING, not the Carbon instance. These responses are cached
            // via SiteCache, and a serialized Carbon returns as
            // __PHP_Incomplete_Class — so the cached copy shipped a broken object
            // where the uncached one shipped a date. Same wire format either way.
            'updated_at'       => $this->updated_at?->toISOString(),
        ];
    }
}
