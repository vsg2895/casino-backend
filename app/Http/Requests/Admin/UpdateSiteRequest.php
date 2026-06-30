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
            'revalidation_url' => ['nullable', 'url', 'max:500'],
            'settings'         => ['nullable', 'array'],
            'active'           => ['sometimes', 'boolean'],
        ];
        // api_key is intentionally absent — it can only change via rotateKey
    }
}
