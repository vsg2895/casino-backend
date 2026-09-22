<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\NewsCategory */
class NewsCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'site_id'  => (int) $this->site_id,
            'name'     => $this->name,
            'slug'     => $this->slug,
            'position' => (int) $this->position,
            'active'   => (bool) $this->active,
            // So the admin can warn before a delete that would leave posts
            // uncategorised.
            'articles_count' => $this->when(isset($this->articles_count), fn () => (int) $this->articles_count),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
