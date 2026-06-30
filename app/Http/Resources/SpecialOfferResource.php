<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpecialOfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'casino_id'          => $this->casino_id,
            'casino'             => new CasinoResource($this->whenLoaded('casino')),
            'title'              => $this->title,
            'slug'               => $this->slug,
            'image_path'         => $this->image_path,
            'banner_image'       => $this->banner_image,
            'bonuses'            => $this->bonuses,
            'affiliate_url'      => $this->affiliate_url,
            'description'        => $this->description,
            'rating'             => (int) $this->rating,
            'sort_order'         => (int) $this->sort_order,
            'active'             => (bool) $this->active,
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        ];
    }
}
