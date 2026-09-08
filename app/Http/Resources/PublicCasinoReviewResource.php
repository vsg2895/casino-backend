<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A published review as visitors see it.
 *
 * A SEPARATE class from {@see CasinoReviewResource} rather than a conditional
 * inside it. The difference between the two is one field — `author_email` — and
 * a conditional is exactly how that field eventually leaks: someone adds a
 * `$this->when(...)` that is true in a context nobody rechecked. Two classes
 * make the public contract auditable by reading one short file.
 *
 * `status` is absent too: everything reaching this resource is published by
 * construction, so returning the column would only tell a visitor that a
 * moderation queue exists.
 */
class PublicCasinoReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'author_name' => $this->author_name,
            'rating'      => (int) $this->rating,
            'title'       => $this->title,
            'body'        => $this->body,
            // The date shown under the review. ISO-8601 STRING, not a Carbon
            // instance: these payloads go through SiteCache, and a serialized
            // Carbon returns as __PHP_Incomplete_Class.
            'published_at' => $this->published_at?->toISOString(),
            'created_at'   => $this->created_at?->toISOString(),
        ];
    }
}
