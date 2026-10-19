<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing CMS page resource (full record, including draft status).
 */
class CmsPageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'site_id'          => $this->site_id,
            'site_name'        => $this->whenLoaded('site', fn () => $this->site->name),
            'slug'             => $this->slug,
            'title'            => $this->title,
            'content'          => $this->content,
            'meta_title'       => $this->meta_title,
            'meta_description' => $this->meta_description,
            'status'           => $this->status,
            /*
             * Whether this page asks to stay out of the index.
             *
             * The front end used to decide this from a hardcoded list of the
             * eleven standard slugs, which meant /about and the other four
             * trust pages were suppressed alongside the six legal ones — and
             * changing that needed a deploy. Now the decision is per page, per
             * site, and editable in the admin.
             */
            'noindex'          => (bool) $this->noindex,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}
