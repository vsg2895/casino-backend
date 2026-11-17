<?php

declare(strict_types=1);

namespace App\Perfume\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Perfume\Models\ScentFamily */
class ScentFamilyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'slug'     => $this->slug,
            'position' => (int) $this->position,
        ];
    }
}
