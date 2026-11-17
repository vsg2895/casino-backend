<?php

declare(strict_types=1);

namespace App\Perfume\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Perfume\Models\Page */
class PageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'slug'             => $this->slug,
            'body'             => $this->body,
            'active'           => (bool) $this->active,
            'position'         => (int) $this->position,
            'meta_title'       => $this->meta_title,
            'meta_description' => $this->meta_description,
            'updated_at'       => $this->updated_at?->toISOString(),
        ];
    }
}
