<?php

declare(strict_types=1);

namespace App\Perfume\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Perfume\Models\Category */
class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'slug'             => $this->slug,
            'title'            => $this->title,
            'description'      => $this->description,
            'image_path'       => $this->image_path,
            'image_url'        => ImageUrl::of($this->image_path),
            'in_header'        => (bool) $this->in_header,
            'active'           => (bool) $this->active,
            'position'         => (int) $this->position,
            'products_count'   => $this->whenCounted('products'),
            'meta_title'       => $this->meta_title,
            'meta_description' => $this->meta_description,
            'updated_at'       => $this->updated_at?->toISOString(),
        ];
    }
}
