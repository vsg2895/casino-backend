<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing review row.
 *
 * `author_email` IS included here and only here — a moderator needs it to spot a
 * repeat submitter. {@see PublicCasinoReviewResource} is the shape visitors get,
 * and it has no such field.
 */
class CasinoReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'site_id'      => (int) $this->site_id,
            'site_name'    => $this->whenLoaded('site', fn () => $this->site->name),
            'casino_id'    => (int) $this->casino_id,
            'casino_name'  => $this->whenLoaded('casino', fn () => $this->casino->name),
            'casino_slug'  => $this->whenLoaded('casino', fn () => $this->casino->slug),
            'author_name'  => $this->author_name,
            // Read straight off the attribute: $hidden keeps it out of implicit
            // serialisation, which is exactly why it has to be named explicitly
            // to appear at all.
            'author_email' => $this->getAttribute('author_email'),
            'rating'       => (int) $this->rating,
            'title'        => $this->title,
            'body'         => $this->body,
            'status'       => $this->status,
            'published_at' => $this->published_at?->toISOString(),
            'created_at'   => $this->created_at?->toISOString(),
            'updated_at'   => $this->updated_at?->toISOString(),
        ];
    }
}
