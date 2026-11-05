<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One site's own review text for one casino. Null or empty clears it, and the
 * site falls back to the shared `casinos.description`.
 */
class UpdateCasinoSiteDescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Same rule as the shared field on UpdateCasinoRequest.
        return [
            'description' => ['present', 'nullable', 'string'],
        ];
    }
}
