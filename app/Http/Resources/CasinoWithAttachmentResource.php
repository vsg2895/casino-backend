<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-facing casino resource.
 * Built from a join so pivot columns (affiliate_url, position, featured) are available
 * directly on the model instance. The per-site affiliate_url overrides the casino default.
 */
class CasinoWithAttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'name'                  => $this->name,
            'slug'                  => $this->slug,
            'image_path'            => $this->image_path,
            'banner_image'          => $this->banner_image,
            'bonuses'               => $this->bonuses,
            'description'           => $this->description,
            'rating'                => (int) $this->rating,
            'meta_title'            => $this->meta_title,
            'meta_description'      => $this->meta_description,
            'categories'            => CategoryResource::collection($this->whenLoaded('categories')),
            'special_offers'        => SpecialOfferResource::collection($this->whenLoaded('specialOffers')),
            'featured_special_offer' => new SpecialOfferResource($this->whenLoaded('featuredSpecialOffer')),
            'updated_at'            => $this->updated_at,
            'attachment'            => [
                'affiliate_url' => $this->affiliate_url,
                'position'      => (int) $this->position,
                'featured'      => (bool) $this->featured,
            ],
        ];
    }
}
