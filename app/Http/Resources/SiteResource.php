<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'slug'             => $this->slug,
            'domain'           => $this->domain,
            'revalidation_url' => $this->revalidation_url,
            'settings'         => $this->settings,
            'active'           => $this->active,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
            // api_key is never included — $hidden on the model is the last line of defence,
            // but we keep it explicit here so a reviewer can audit the contract in one place.
        ];
    }
}
