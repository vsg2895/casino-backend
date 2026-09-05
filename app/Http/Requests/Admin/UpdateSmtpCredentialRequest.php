<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SmtpCredential;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a stored SMTP server.
 *
 * `password` is NULLABLE here and required on create. A blank field means "keep
 * the stored password" — the admin never sees the current value, so requiring it
 * on every edit would force them to retype a secret they cannot read just to
 * change a port. {@see \App\Http\Controllers\Api\Admin\SmtpCredentialController::update()}
 * strips the empty value before saving.
 */
class UpdateSmtpCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'     => [
                'required', 'string', 'max:120',
                // Ignore this row, or saving an unchanged name would collide
                // with itself.
                Rule::unique('smtp_credentials', 'name')->ignore($this->route('smtp_credential')),
            ],
            'type'     => ['required', Rule::in(SmtpCredential::TYPES)],
            'host'     => ['required', 'string', 'max:255', 'regex:/^(?!https?:\/\/)[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'port'     => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'encryption'   => ['required', Rule::in(SmtpCredential::ENCRYPTIONS)],
            'from_address' => ['required', 'email:rfc', 'max:255'],
            'from_name'    => ['nullable', 'string', 'max:120'],
            'status'       => ['sometimes', Rule::in(SmtpCredential::STATUSES)],
        ];
    }
}
