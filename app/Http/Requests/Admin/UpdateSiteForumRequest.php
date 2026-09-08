<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SiteForum;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the forum page settings.
 *
 * Every text field is `nullable`: clearing one is a legitimate edit that reverts
 * it to the shipped wording (see {@see SiteForum::resolved()}). `sometimes` on
 * each rule keeps the endpoint a partial update, so the screen can save one
 * toggle without resubmitting the whole page.
 */
class UpdateSiteForumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled'          => ['sometimes', 'boolean'],
            'title'            => ['sometimes', 'nullable', 'string', 'max:120'],
            'eyebrow'          => ['sometimes', 'nullable', 'string', 'max:120'],
            'show_eyebrow'     => ['sometimes', 'boolean'],
            'intro'            => ['sometimes', 'nullable', 'string', 'max:2000'],
            'meta_title'       => ['sometimes', 'nullable', 'string', 'max:180'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'noindex'          => ['sometimes', 'boolean'],
            'empty_title'      => ['sometimes', 'nullable', 'string', 'max:180'],
            'empty_body'       => ['sometimes', 'nullable', 'string', 'max:2000'],
            'empty_cta_label'  => ['sometimes', 'nullable', 'string', 'max:80'],
            // A SITE-RELATIVE path only. An absolute URL here would turn the
            // empty state's primary button into an off-site link that no other
            // admin screen would ever show.
            'empty_cta_url'    => ['sometimes', 'nullable', 'string', 'max:200', 'regex:/^\/[A-Za-z0-9\-\/_?=&#.]*$/'],
            'show_stats'       => ['sometimes', 'boolean'],
            'threads_per_page' => ['sometimes', 'integer', 'min:1', 'max:' . SiteForum::MAX_THREADS_PER_PAGE],
            'preview_reviews'  => ['sometimes', 'integer', 'min:1', 'max:' . SiteForum::MAX_PREVIEW_REVIEWS],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'empty_cta_url.regex' => 'Use a path on this site, starting with "/" — for example /casinos.',
        ];
    }
}
