<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One test send through a stored SMTP credential.
 *
 * Deliberately smaller than {@see SendMailgunKeyTestRequest}: that dialog picks
 * a template from the shared catalog, because a Mailgun credential can send any
 * of the site templates. This channel only ever sends the credential's own
 * configured receiver message, so there is nothing to pick.
 */
class SendSmtpCredentialTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to'   => ['required', 'string', 'email', 'max:180'],
            // Optional — drives the {{name}} substitution, as in the other tests.
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
