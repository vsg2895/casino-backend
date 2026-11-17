<?php

declare(strict_types=1);

namespace App\Perfume\Http\Resources;

use App\Perfume\Models\Product;
use App\Perfume\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product as a catalog card: what the grids on the home, catalog, brand
 * and note pages show. `meta` is the design's "Eau de toilette · 100 ml"
 * line, from the largest non-sample volume; `price` the lowest priced
 * full-size variant, null when no variant has a price yet.
 *
 * @mixin Product
 */
class ProductCardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var \Illuminate\Support\Collection<int, ProductVariant> $full */
        $full = $this->variants->where('is_sample', false);
        $priced = $full->whereNotNull('price')->sortBy('price')->first();
        // The volume on the card: the cheapest priced one, else the first in admin order.
        $shown = $priced ?? $full->first();
        $image = $this->images->first();

        return [
            'id'            => $this->id,
            'slug'          => $this->slug,
            'name'          => $this->name,
            'sku'           => $this->sku,
            'brand'         => $this->brand?->name,
            'brand_slug'    => $this->brand?->slug,
            'gender'        => $this->gender,
            'type'          => $this->type,
            'type_label'    => Product::TYPES[$this->type] ?? $this->type,
            'meta'          => implode(' · ', array_filter([
                Product::TYPES[$this->type] ?? $this->type,
                $shown?->label,
            ])),
            // What a card's "Add to cart" adds: the shown variant.
            'default_variant_id'    => $shown?->id,
            'default_variant_label' => $shown?->label,
            'price'         => $priced?->price,
            'old_price'     => $priced?->old_price,
            'in_stock'      => $full->sum('stock') > 0,   // a full bottle, not only the sample
            'has_sample'    => $this->variants->contains('is_sample', true),
            'is_new'        => (bool) $this->is_new,
            'is_on_sale'    => (bool) $this->is_on_sale,
            'tint'          => $this->tint,
            'image_url'     => ImageUrl::of($image?->path),
            'image_alt'     => $image?->alt,
            'scent_family'  => $this->scentFamily?->name,
        ];
    }
}
