<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\NavItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An edited navigation link. Same rules as creation — there is no field here
 * that only makes sense once.
 *
 * `url` accepts an internal path OR an absolute http(s) URL and nothing else.
 * A bare "casinos" with no leading slash resolves relative to the current page
 * and breaks on every route but the home page, so it is rejected rather than
 * silently repaired — an editor who typed it meant "/casinos" and should be told.
 */
class UpdateNavItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'location' => ['required', Rule::in(NavItem::LOCATIONS)],
            'label'    => ['required', 'string', 'max:80'],
            'url'      => ['required', 'string', 'max:500', 'regex:#^(/|https?://)#'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'active'   => ['sometimes', 'boolean'],
            'opens_in_new_tab' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'url.regex' => 'Start an internal link with "/" (e.g. /casinos), or paste a full https:// address.',
        ];
    }
}
