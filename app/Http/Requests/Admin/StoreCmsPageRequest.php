<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\CmsPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCmsPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CmsPage::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'site_id'          => ['required', 'integer', 'exists:sites,id'],
            // Slugs are unique per site, not globally.
            'slug'             => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('cms_pages', 'slug')->where('site_id', $this->integer('site_id')),
            ],
            'title'            => ['required', 'string', 'max:255'],
            'content'          => ['nullable', 'string'],
            'meta_title'       => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'status'           => ['nullable', Rule::in([CmsPage::STATUS_DRAFT, CmsPage::STATUS_PUBLISHED])],
            // Per-record SEO overrides. Offers carry no meta_title/meta_description
            // columns, so a title here comes from the site's pattern; these two
            // are the only per-record SEO controls an offer has.
            'canonical_url' => ['nullable', 'string', 'max:500', 'regex:#^https?://#'],
            'noindex'       => ['sometimes', 'boolean'],
            // Byline: only an author of the page's OWN site — a person from
            // another brand must never be named on this one.
            'author_id'        => ['nullable', 'integer', Rule::exists('authors', 'id')->where('site_id', $this->integer('site_id'))],
            'reviewed_by_id'   => ['nullable', 'integer', Rule::exists('authors', 'id')->where('site_id', $this->integer('site_id'))],
            'last_reviewed_at' => ['nullable', 'date'],
            // Pending revision; the live columns above stay public meanwhile.
            'draft_title'            => ['nullable', 'string', 'max:255'],
            'draft_content'          => ['nullable', 'string'],
            'draft_meta_title'       => ['nullable', 'string', 'max:255'],
            'draft_meta_description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
