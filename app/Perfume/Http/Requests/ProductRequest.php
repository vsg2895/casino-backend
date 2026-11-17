<?php

declare(strict_types=1);

namespace App\Perfume\Http\Requests;

use App\Perfume\Models\Product;
use Illuminate\Validation\Rule;

/**
 * One payload for the whole product editor: the product's own fields plus
 * its categories, variants, images and notes, written together by
 * {@see \App\Perfume\Services\ProductWriter}. Nested lists REPLACE the
 * stored ones when present and are left alone when absent.
 */
class ProductRequest extends PerfumeRequest
{
    protected function routeKey(): string
    {
        return 'product';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $req = $creating ? 'required' : 'sometimes';

        return [
            'brand_id'        => [$req, 'integer', 'exists:perfume_brands,id'],
            'perfumer_id'     => ['nullable', 'integer', 'exists:perfume_perfumers,id'],
            'scent_family_id' => ['nullable', 'integer', 'exists:perfume_scent_families,id'],
            'name'            => [$req, 'string', 'max:255'],
            'slug'            => $this->slugRule('perfume_products', $creating),
            'sku'             => ['nullable', 'string', 'max:64'],
            'gender'          => [$req, Rule::in(Product::GENDERS)],
            'type'            => [$req, Rule::in(array_keys(Product::TYPES))],
            'year'            => ['nullable', 'integer', 'min:1800', 'max:2100'],
            'country'         => ['nullable', 'string', 'max:255'],
            'description'     => ['nullable', 'string', 'max:20000'],
            'tint'            => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'rating'          => ['nullable', 'numeric', 'min:0', 'max:5'],
            'reviews_count'   => ['nullable', 'integer', 'min:0'],
            'is_new'          => ['boolean'],
            'is_on_sale'      => ['boolean'],
            'active'          => ['boolean'],
            'position'        => ['nullable', 'integer', 'min:0'],
            ...$this->seoRules(),

            'category_ids'    => ['sometimes', 'array'],
            'category_ids.*'  => ['integer', 'exists:perfume_categories,id'],

            'variants'             => ['sometimes', 'array', 'max:20'],
            'variants.*.volume_ml' => ['required', 'integer', 'min:1', 'max:5000'],
            'variants.*.label'     => ['required', 'string', 'max:64'],
            'variants.*.price'     => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'variants.*.old_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'variants.*.stock'     => ['nullable', 'integer', 'min:0'],
            'variants.*.is_sample' => ['boolean'],

            'images'        => ['sometimes', 'array', 'max:12'],
            'images.*.path' => ['required', 'string', 'max:255'],
            'images.*.alt'  => ['nullable', 'string', 'max:255'],

            'notes'                 => ['sometimes', 'array', 'max:40'],
            'notes.*.scent_note_id' => ['required', 'integer', 'exists:perfume_scent_notes,id'],
            'notes.*.layer'         => ['required', Rule::in(Product::LAYERS)],
        ];
    }
}
