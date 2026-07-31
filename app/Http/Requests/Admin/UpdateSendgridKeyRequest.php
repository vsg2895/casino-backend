<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SendgridKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * On edit the API key is OPTIONAL: leave it blank to keep the stored key (the
 * admin never sees the raw value, so re-typing it every edit is impractical);
 * provide a new value to rotate it.
 */
class UpdateSendgridKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'    => ['required', 'string', 'max:120'],
            'api_key' => ['nullable', 'string', 'min:20', 'max:500'],
            'status'  => ['sometimes', Rule::in(SendgridKey::STATUSES)],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Treat a blank/null key from the UI as "keep the existing key" — the
        // stored value must never be silently wiped by an edit. Note: the SPA
        // sends JSON, where ConvertEmptyStringsToNull has already turned ''
        // into null and the payload lives in the JSON bag (not $this->request),
        // so strip it from the actual input source.
        if ($this->input('api_key') === null || $this->input('api_key') === '') {
            $this->getInputSource()->remove('api_key');
        }
    }
}
