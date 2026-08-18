<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateCasinoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the slug before validation, so "Bit Starz" is accepted and
     * stored as "bit-starz" rather than rejected on format.
     *
     * Unlike create, a blank value is NOT dropped here: the model deliberately
     * never regenerates a slug on update (a rename must not silently move a live
     * URL), so there is nothing to fall back to. It stays empty and fails the
     * `required` rule with a clear message instead of writing an empty slug that
     * would make the casino unreachable on every public site.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('slug')) {
            return;
        }

        $this->merge(['slug' => Str::slug(trim((string) $this->input('slug')))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'                      => ['sometimes', 'required', 'string', 'max:255'],
            // Checked against the raw table so a soft-deleted casino's slug still
            // counts as taken — the DB unique index does not carry `deleted_at`,
            // so ignoring trashed rows here would turn a 422 into a 500 at UPDATE.
            'slug'                      => [
                'sometimes', 'required', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('casinos', 'slug')->ignore($this->route('casino')),
            ],
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
            'slug.required' => 'The slug cannot be empty. It is this casino\'s public URL on every site.',
            'slug.regex'    => 'The slug may only contain lowercase letters, numbers and single hyphens.',
            'slug.unique'   => 'That slug is already taken by another casino.',
        ];
    }
}
