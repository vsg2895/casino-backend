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
            'name'    => ['required', 'string', 'max:120'],
            // The sending domain registered in Mailgun (e.g. mg.example.com).
            // Validated as a hostname, not a URL — a scheme here is a common
            // mistake that only surfaces later as an opaque API 404.
            'domain'  => ['required', 'string', 'max:255', 'regex:/^(?!https?:\/\/)[a-z0-9.-]+\.[a-z]{2,}$/i'],
            // Mailgun private keys are long opaque strings; keep the check loose
            // but reject obviously-empty/short values, as the SendGrid rule does.
            'api_key' => ['required', 'string', 'min:20', 'max:500'],
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
