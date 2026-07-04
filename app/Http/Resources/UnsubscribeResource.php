<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Unsubscribe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Unsubscribe */
class UnsubscribeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'site_id'         => $this->site_id,
            'site'            => new SiteResource($this->whenLoaded('site')),
            'email'           => $this->email,
            'type'            => $this->type,
            'unsubscribed_at' => $this->unsubscribed_at,
            'created_at'      => $this->created_at,
        ];
    }
}
