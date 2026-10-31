<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Author;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Author
 *
 * The full record. Used by the admin, and by the public author page — every
 * field here is something the person's public profile shows; `visible` and the
 * ids are harmless there and keep one resource instead of two that drift.
 */
class AuthorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'site_id'       => $this->site_id,
            'name'          => $this->name,
            'slug'          => $this->slug,
            'photo_path'    => $this->photo_path,
            'role'          => $this->role,
            'short_bio'     => $this->short_bio,
            'long_bio'      => $this->long_bio,
            'expertise'     => array_values($this->expertise ?? []),
            'profile_links' => array_values($this->profile_links ?? []),
            'visible'       => (bool) $this->visible,
            // Strings, never Carbon: the public copy is cached by SiteCache.
            'created_at'    => $this->created_at?->toISOString(),
            'updated_at'    => $this->updated_at?->toISOString(),
        ];
    }
}
