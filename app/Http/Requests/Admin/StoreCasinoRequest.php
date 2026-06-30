<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreCasinoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'                      => ['required', 'string', 'max:255'],
            'image_path'                => ['nullable', 'string', 'max:500'],
            'banner_image'              => ['nullable', 'string', 'max:500'],
            'bonuses'                   => ['nullable', 'string', 'max:255'],
            'affiliate_url'             => ['nullable', 'url', 'max:500'],
            'description'               => ['nullable', 'string'],
            'rating'                    => ['nullable', 'integer', 'min:0', 'max:5'],
            'sort_order'                => ['nullable', 'integer', 'min:0'],
            'featured_special_offer_id' => ['nullable', 'integer', 'exists:special_offers,id'],
            'meta_title'                => ['nullable', 'string', 'max:255'],
            'meta_description'          => ['nullable', 'string', 'max:500'],
            'active'                    => ['boolean'],
            'category_ids'              => ['nullable', 'array'],
            'category_ids.*'            => ['integer', 'exists:categories,id'],
        ];
    }
}
