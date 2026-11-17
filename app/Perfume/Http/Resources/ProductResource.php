<?php

declare(strict_types=1);

namespace App\Perfume\Http\Resources;

use App\Perfume\Models\Product;
use App\Perfume\Models\ProductNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full product: everything the product page and the admin editor need.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $notes = ['top' => [], 'heart' => [], 'base' => []];

        /** @var ProductNote $note */
        foreach ($this->notes as $note) {
            if ($note->scentNote !== null) {
                $notes[$note->layer][] = [
                    'id'   => $note->scentNote->id,
                    'name' => $note->scentNote->name,
                    'slug' => $note->scentNote->slug,
                ];
            }
        }

        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'slug'             => $this->slug,
            'sku'              => $this->sku,
            'gender'           => $this->gender,
            'type'             => $this->type,
            'type_label'       => Product::TYPES[$this->type] ?? $this->type,
            'year'             => $this->year,
            'country'          => $this->country,
            'description'      => $this->description,
            'tint'             => $this->tint,
            'rating'           => $this->rating,
            'reviews_count'    => $this->reviews_count,
            'is_new'           => (bool) $this->is_new,
            'is_on_sale'       => (bool) $this->is_on_sale,
            'active'           => (bool) $this->active,
            'position'         => (int) $this->position,
            'meta_title'       => $this->meta_title,
            'meta_description' => $this->meta_description,
            'brand_id'         => $this->brand_id,
            'brand'            => $this->brand ? ['id' => $this->brand->id, 'name' => $this->brand->name, 'slug' => $this->brand->slug] : null,
            'perfumer_id'      => $this->perfumer_id,
            'perfumer'         => $this->perfumer ? ['id' => $this->perfumer->id, 'name' => $this->perfumer->name, 'slug' => $this->perfumer->slug] : null,
            'scent_family_id'  => $this->scent_family_id,
            'scent_family'     => $this->scentFamily ? ['id' => $this->scentFamily->id, 'name' => $this->scentFamily->name, 'slug' => $this->scentFamily->slug] : null,
            'categories'       => $this->categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'title' => $c->title])->values(),
            'category_ids'     => $this->categories->pluck('id')->values(),
            'variants'         => ProductVariantResource::collection($this->variants),
            'images'           => $this->images->map(fn ($i) => [
                'id'       => $i->id,
                'path'     => $i->path,
                'url'      => ImageUrl::of($i->path),
                'alt'      => $i->alt,
                'position' => (int) $i->position,
            ])->values(),
            'notes'            => $notes,
            'updated_at'       => $this->updated_at?->toISOString(),
        ];
    }
}
