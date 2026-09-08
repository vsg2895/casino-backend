<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:255'],
            'domain'           => ['required', 'string', 'max:253', 'unique:sites,domain'],
            // One short sentence saying what makes this brand different. It is
            // woven into every generated legal page's meta description, so the
            // eleven standard pages stop reading identically across domains.
            'positioning'      => ['nullable', 'string', 'max:200'],
            'revalidation_url' => ['nullable', 'url', 'max:500'],
            'settings'         => ['nullable', 'array'],
            'active'           => ['boolean'],
            'newsletter_emails_enabled' => ['boolean'],
            // Opt-in per site. Absent means the column default (false) stands.
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
    }
}
