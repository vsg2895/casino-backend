<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SocialLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSocialLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'site_id'    => ['required', 'integer', 'exists:sites,id'],
            'platform'   => ['required', 'string', Rule::in(SocialLink::PLATFORMS)],
            'label'      => ['nullable', 'string', 'max:255'],
            'url'        => ['required', 'url', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active'     => ['boolean'],
        ];
    }
}
