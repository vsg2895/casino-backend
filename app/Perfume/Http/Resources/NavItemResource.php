<?php

declare(strict_types=1);

namespace App\Perfume\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Perfume\Models\NavItem */
class NavItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'location'    => $this->location,
            'group_label' => $this->group_label,
            'label'       => $this->label,
            'url'         => $this->url,
            'active'      => (bool) $this->active,
            'position'    => (int) $this->position,
        ];
    }
}
