<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PromotionEmailHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PromotionEmailHistory */
class PromotionEmailHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'site_id'    => $this->site_id,
            'site'       => new SiteResource($this->whenLoaded('site')),
            'email'      => $this->email,
            'sent_date'  => $this->sent_date?->format('Y-m-d'),
            'created_at' => $this->created_at,
        ];
    }
}
