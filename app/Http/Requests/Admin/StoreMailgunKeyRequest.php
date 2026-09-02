<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\MailgunKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMailgunKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already behind auth:sanctum.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'    => ['required', 'string', 'max:120', Rule::unique('mailgun_keys', 'name')],
            // The sending domain registered in Mailgun (e.g. mg.example.com).
            // Validated as a hostname, not a URL — a scheme here is a common
            // mistake that only surfaces later as an opaque API 404.
            'domain'  => ['required', 'string', 'max:255', 'regex:/^(?!https?:\/\/)[a-z0-9.-]+\.[a-z]{2,}$/i'],
            // Mailgun private keys are long opaque strings; keep the check loose
            // but reject obviously-empty/short values, as the SendGrid rule does.
            'api_key' => ['required', 'string', 'min:20', 'max:500'],
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
}
