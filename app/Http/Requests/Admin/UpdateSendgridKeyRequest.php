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
            // The sender of receiver campaigns, which have no site template
            // to take one from. Must be a verified sender in SendGrid. Optional:
            // schedules and the test button never read it.
            'from_address' => ['nullable', 'email:rfc', 'max:255'],
            'from_name'    => ['nullable', 'string', 'max:120'],
            // The Signed Event Webhook's verification key, pasted from SendGrid.
            // A public key, so no masking; the format is checked when an event
            // arrives, and a key that cannot be parsed simply verifies nothing.
            'webhook_verification_key' => ['nullable', 'string', 'max:2000'],
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
