<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewsletterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'site_id'    => $this->site_id,
            'site'       => new SiteResource($this->whenLoaded('site')),
            'email'      => $this->email,
            'full_name'  => $this->full_name,
            'verified'   => (bool) $this->verified,
            'created_at' => $this->created_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
