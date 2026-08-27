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
        ];
    }
}
