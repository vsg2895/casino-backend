<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing every casino/country attachment, which has no undo, so it must be
 * confirmed explicitly in the body.
 */
class DetachAllCasinoCountriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirm.required' => 'Send confirm=true — this removes every casino/country attachment.',
            'confirm.accepted' => 'Send confirm=true — this removes every casino/country attachment.',
        ];
    }
}
