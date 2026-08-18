<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'slug'          => $this->slug,
            'sort_order'    => (int) $this->sort_order,
            'casinos_count' => $this->when(isset($this->casinos_count), fn () => (int) $this->casinos_count),
            // ISO-8601 STRING, not the Carbon instance. These responses are cached
            // via SiteCache, and a serialized Carbon returns as
            // __PHP_Incomplete_Class — so the cached copy shipped a broken object
            // where the uncached one shipped a date. Same wire format either way.
            'created_at'    => $this->created_at?->toISOString(),
            'updated_at'    => $this->updated_at?->toISOString(),
        ];
    }
}
