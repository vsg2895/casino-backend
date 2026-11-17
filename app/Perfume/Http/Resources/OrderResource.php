<?php

declare(strict_types=1);

namespace App\Perfume\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Perfume\Models\Order */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'reference'     => $this->reference,
            'customer_name' => $this->customer_name,
            'phone'         => $this->phone,
            'email'         => $this->email,
            'address'       => $this->address,
            'comment'       => $this->comment,
            'status'        => $this->status,
            'total'         => $this->total,
            'items_count'   => (int) $this->items_count,
            'items'         => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id'            => $i->id,
                'brand_name'    => $i->brand_name,
                'product_name'  => $i->product_name,
                'product_slug'  => $i->product_slug,
                'variant_label' => $i->variant_label,
                'price'         => $i->price,
                'qty'           => (int) $i->qty,
            ])->values()),
            'created_at'    => $this->created_at?->toISOString(),
            'updated_at'    => $this->updated_at?->toISOString(),
        ];
    }
}
