<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BonusCategory */
class BonusCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'position'    => (int) $this->position,
            'active'      => (bool) $this->active,
            // How many offers are filed here, so the admin list can warn before
            // a delete that would leave offers uncategorised.
            'offers_count' => $this->when(isset($this->special_offers_count), fn () => (int) $this->special_offers_count),
            'created_at'  => $this->created_at?->toISOString(),
            'updated_at'  => $this->updated_at?->toISOString(),
        ];
    }
}
