<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AttachCasinoToSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'site_id'       => ['required', 'integer', 'exists:sites,id'],
            'affiliate_url' => ['required', 'url', 'max:500'],
            'position'      => ['integer', 'min:0'],
            'featured'      => ['boolean'],
            'active'        => ['boolean'],
        ];
    }
}
