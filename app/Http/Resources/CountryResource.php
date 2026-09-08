<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'continent_id'  => (int) $this->continent_id,
            // Denormalised so the public grid can group without a second request.
            // whenLoaded, so the admin list does not pay for a join it may not need.
            'continent'     => $this->whenLoaded('continent', fn () => [
                'id'       => $this->continent->id,
                'name'     => $this->continent->name,
                'slug'     => $this->continent->slug,
                'position' => (int) $this->continent->position,
            ]),
            'name'          => $this->name,
            'slug'          => $this->slug,
            // Null for the entries that are not countries — the Europe-wide and
            // "Arab" cards.
            'code'          => $this->code,
            'image_path'    => $this->image_path,
            'position'      => (int) $this->position,
            'active'        => (bool) $this->active,
            'casinos_count' => $this->when(isset($this->casinos_count), fn () => (int) $this->casinos_count),
            // ISO-8601 STRINGS, not Carbon instances: these responses are cached
            // through SiteCache, and a serialized Carbon comes back as
            // __PHP_Incomplete_Class. Same wire format either way.
            'created_at'    => $this->created_at?->toISOString(),
            'updated_at'    => $this->updated_at?->toISOString(),
        ];
    }
}
