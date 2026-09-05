<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SmtpCredential;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a stored SMTP server.
 *
 * The password IS required here, unlike on update: a credential created without
 * one could never authenticate, and would sit in the list looking configured.
 */
class StoreSmtpCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:120', Rule::unique('smtp_credentials', 'name')],
            // No scheme, no path — a bare hostname, the way an SMTP client wants
            // it. Pasting "https://mail.example.com" is the common mistake and it
            // fails at connect time with an unhelpful error.
            'type'     => ['required', Rule::in(SmtpCredential::TYPES)],
            'host'     => ['required', 'string', 'max:255', 'regex:/^(?!https?:\/\/)[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'port'     => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:500'],
            'encryption' => ['required', Rule::in(SmtpCredential::ENCRYPTIONS)],
            // Required, unlike the Mailgun equivalent: this value is actually
            // sent as the From, and most servers reject a From they do not own.
            'from_address' => ['required', 'email:rfc', 'max:255'],
            'from_name'    => ['nullable', 'string', 'max:120'],
            'status'       => ['sometimes', Rule::in(SmtpCredential::STATUSES)],
        ];
    }
}
