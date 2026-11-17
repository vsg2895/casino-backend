<?php

declare(strict_types=1);

namespace App\Perfume\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Perfume\Models\ProductVariant */
class ProductVariantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'volume_ml' => (int) $this->volume_ml,
            'label'     => $this->label,
            'price'     => $this->price,
            'old_price' => $this->old_price,
            'stock'     => (int) $this->stock,
            'in_stock'  => (int) $this->stock > 0,
            'is_sample' => (bool) $this->is_sample,
            'position'  => (int) $this->position,
        ];
    }
}
