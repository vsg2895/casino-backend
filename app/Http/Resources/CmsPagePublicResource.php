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
            'updated_at'       => $this->updated_at,
        ];
    }
}
