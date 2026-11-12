<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One site's content override for one casino or special offer.
 *
 * Every field is optional; empty means "use the default", and a save that
 * leaves nothing overridden removes the row (SiteContentEditor). `noindex`
 * is tri-state: null = inherit, true = Noindex, false = Index.
 */
class UpdateSiteContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Same limits as the default fields on UpdateCasinoRequest.
        return [
            'description'      => ['nullable', 'string'],
            'meta_title'       => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'noindex'          => ['nullable', 'boolean'],
        ];
    }
}
