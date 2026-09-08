<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $siteId = $this->route('site')?->id;

        return [
            'name'             => ['sometimes', 'string', 'max:255'],
            'domain'           => ['sometimes', 'string', 'max:253', Rule::unique('sites', 'domain')->ignore($siteId)->withoutTrashed()],
            // One short sentence saying what makes this brand different. It is
            // woven into every generated legal page's meta description, so the
            // eleven standard pages stop reading identically across domains.
            'positioning'      => ['nullable', 'string', 'max:200'],
            'revalidation_url' => ['nullable', 'url', 'max:500'],
            'settings'         => ['nullable', 'array'],
            'active'           => ['sometimes', 'boolean'],
            // Off keeps the signup form and the stored subscriber; only the
            // outbound mail stops.
            'newsletter_emails_enabled' => ['sometimes', 'boolean'],
            'countries_enabled' => ['sometimes', 'boolean'],
            'reviews_enabled'   => ['sometimes', 'boolean'],
            'operator_profile_enabled' => ['sometimes', 'boolean'],
            // Editorial identity. The switch and the name validate
            // independently, but Site::editorialAuthor() refuses to publish a
            // byline without a name.
            'byline_enabled'    => ['sometimes', 'boolean'],
            'guides_enabled'    => ['sometimes', 'boolean'],
            'author_name'       => ['nullable', 'string', 'max:120'],
            'author_role'       => ['nullable', 'string', 'max:120'],
            'author_bio'        => ['nullable', 'string', 'max:2000'],
            'author_avatar_path' => ['nullable', 'string', 'max:500'],
            'methodology_page_slug' => ['nullable', 'string', 'max:120'],
        ];
        // api_key is intentionally absent — it can only change via rotateKey
    }
}
