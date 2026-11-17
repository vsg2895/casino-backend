<?php

declare(strict_types=1);

namespace App\Perfume\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Perfume\Models\Store */
class StoreResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'address'  => $this->address,
            'hours'    => $this->hours,
            'map_url'  => $this->map_url,
            'active'   => (bool) $this->active,
            'position' => (int) $this->position,
        ];
    }
}
