<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\MailgunKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * On edit the API key is OPTIONAL: leave it blank to keep the stored key (the
 * admin never sees the raw value, so re-typing it every edit is impractical);
 * provide a new value to rotate it. Mirrors UpdateSendgridKeyRequest exactly.
 */
class UpdateMailgunKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'    => [
                'required', 'string', 'max:120',
                // Ignore this row, or saving an unchanged name would collide
                // with itself.
                Rule::unique('mailgun_keys', 'name')->ignore($this->route('mailgun_key')),
            ],
            'domain'  => ['required', 'string', 'max:255', 'regex:/^(?!https?:\/\/)[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'api_key' => ['nullable', 'string', 'min:20', 'max:500'],
            // Sender identity — OPTIONAL, recorded for reference only; no send
            // path reads it. Still validated as a real address so a stored value
            // is never malformed.
            'from_address' => ['nullable', 'email:rfc', 'max:255'],
            'from_name'    => ['nullable', 'string', 'max:120'],
            'region'  => ['sometimes', Rule::in(MailgunKey::REGIONS)],
            'status'  => ['sometimes', Rule::in(MailgunKey::STATUSES)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'domain.regex' => 'Enter the bare sending domain registered in Mailgun, e.g. mg.example.com (no https://).',
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
