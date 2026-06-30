<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\CmsPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCmsPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $page = $this->route('page');

        return $page instanceof CmsPage
            && ($this->user()?->can('update', $page) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var CmsPage $page */
        $page = $this->route('page');

        return [
            'slug'             => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('cms_pages', 'slug')->where('site_id', $page->site_id)->ignore($page->id)],
            'title'            => ['sometimes', 'required', 'string', 'max:255'],
            'content'          => ['nullable', 'string'],
            'meta_title'       => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'status'           => ['sometimes', Rule::in([CmsPage::STATUS_DRAFT, CmsPage::STATUS_PUBLISHED])],
        ];
    }
}
