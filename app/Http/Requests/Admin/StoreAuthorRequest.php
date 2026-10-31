<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A person on one site's team. The site comes from the route, never the
 * payload. Nothing here is required beyond the name: an author row may be
 * created first and filled in before `visible` is switched on.
 */
class StoreAuthorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $siteId = $this->route('site')?->id;
        $authorId = $this->route('author')?->id;

        return [
            'name'       => ['required', 'string', 'max:120'],
            'slug'       => [
                'nullable', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('authors', 'slug')->where('site_id', $siteId)->ignore($authorId),
            ],
            'photo_path' => ['nullable', 'string', 'max:500'],
            'role'       => ['nullable', 'string', 'max:120'],
            'short_bio'  => ['nullable', 'string', 'max:300'],
            'long_bio'   => ['nullable', 'string', 'max:20000'],
            'expertise'   => ['nullable', 'array', 'max:12'],
            'expertise.*' => ['string', 'max:60'],
            // Becomes Person.sameAs — absolute https links only.
            'profile_links'         => ['nullable', 'array', 'max:10'],
            'profile_links.*.label' => ['required', 'string', 'max:60'],
            'profile_links.*.url'   => ['required', 'url:https', 'max:500'],
            'visible'    => ['sometimes', 'boolean'],
        ];
    }
}
