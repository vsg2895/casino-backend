<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreCasinoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the slug before it is validated, so the admin can type a title
     * ("Bit Starz") and still get a valid slug ("bit-starz") rather than a
     * format error. A value that slugifies to nothing (e.g. "!!!") stays empty
     * and is caught by the regex below instead of silently becoming ''.
     *
     * Left absent when blank: the model then generates the slug from the name,
     * which is the existing create behaviour and what the field's placeholder
     * promises.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('slug')) {
            return;
        }

        $slug = trim((string) $this->input('slug'));

        if ($slug === '') {
            $this->request->remove('slug');

            return;
        }

        $this->merge(['slug' => Str::slug($slug)]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'                      => ['required', 'string', 'max:255'],
            // Optional on create — omitted means "generate it from the name".
            // Uniqueness is checked against the raw table, which is what we want:
            // the DB's unique index does not carry `deleted_at`, so a trashed
            // casino still owns its slug and would otherwise fail at INSERT.
            'slug'                      => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:casinos,slug'],
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

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex'  => 'The slug may only contain lowercase letters, numbers and single hyphens.',
            'slug.unique' => 'That slug is already taken by another casino.',
        ];
    }
}
