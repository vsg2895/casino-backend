<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SyncCasinoSitesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sites'                  => ['required', 'array'],
            'sites.*.site_id'        => ['required', 'integer', 'exists:sites,id'],
            'sites.*.affiliate_url'  => ['required', 'url', 'max:500'],
            'sites.*.position'       => ['integer', 'min:0'],
            'sites.*.featured'       => ['boolean'],
            'sites.*.active'         => ['boolean'],
        ];
    }
}
