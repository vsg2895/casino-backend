<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One suggestion row.
 *
 * `image_url` carries the entity's STORED relative path, the same value every
 * other public endpoint returns for an image, so the front end resolves it with
 * its existing helper rather than this API guessing at a host.
 *
 * Nothing here identifies a person: a forum row exposes the casino's name and
 * the review's own headline, never the reviewer's email — which is `$hidden` on
 * the model and is not copied into the index at all.
 *
 * @property array<string, mixed> $resource
 */
class SearchSuggestionResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'title'         => (string) $this->resource['title'],
            'subtitle'      => $this->resource['subtitle'] ?? null,
            'url'           => (string) $this->resource['url'],
            'image_url'     => $this->resource['image_url'] ?? null,
            'section'       => (string) $this->resource['section'],
            'section_label' => (string) $this->resource['section_label'],
        ];
    }
}
