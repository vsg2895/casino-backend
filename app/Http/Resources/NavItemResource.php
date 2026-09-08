<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NavItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NavItem
 *
 * Serves the admin list and the public menu. `site_id` is included for the admin
 * screen and is harmless publicly — it is an internal id, not a secret, and the
 * public endpoint is already site-scoped by the key that reached it.
 */
class NavItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'site_id'  => $this->site_id,
            'location' => $this->location,
            'label'    => $this->label,
            'url'      => $this->url,
            'position' => (int) $this->position,
            'active'   => (bool) $this->active,
            'opens_in_new_tab' => (bool) $this->opens_in_new_tab,
        ];
    }
}
