<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A guide.
 *
 * `published_at` is the ONLY publication control: null is a draft, a past date
 * is live, a future date is scheduled. There is deliberately no separate
 * "published" boolean to contradict it.
 *
 * The slug is unique PER SITE — two domains may each have a
 * "bonus-terms-explained" — and is generated from the title when omitted.
 */
class UpdateArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title'   => ['required', 'string', 'max:255'],
            'slug'    => [
                'nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('articles', 'slug')
                    ->where('site_id', $this->route('site')?->id)
                    ->ignore($this->route('article')?->id),
            ],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body'    => ['nullable', 'string'],
            'hero_image_path' => ['nullable', 'string', 'max:500'],
            'published_at'    => ['nullable', 'date'],
            'position'        => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'meta_title'      => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'canonical_url'   => ['nullable', 'string', 'max:500', 'regex:#^https?://#'],
            'noindex'         => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex'  => 'Use lowercase words separated by hyphens, e.g. bonus-terms-explained.',
            'slug.unique' => 'This site already has a guide with that slug.',
        ];
    }
}
