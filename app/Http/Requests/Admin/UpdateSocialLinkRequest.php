<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SocialLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSocialLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // A link's owning site is immutable; only its presentation fields change.
        return [
            'platform'   => ['sometimes', 'required', 'string', Rule::in(SocialLink::PLATFORMS)],
            'label'      => ['nullable', 'string', 'max:255'],
            'url'        => ['sometimes', 'required', 'url', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active'     => ['boolean'],
        ];
    }
}
