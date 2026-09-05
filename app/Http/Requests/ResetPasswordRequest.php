<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Completing a password reset.
 *
 * The token is the credential here — it arrives from the emailed link, not from
 * a signed-in session — so this route is public and hard-throttled in
 * `routes/api.php`.
 *
 * `confirmed` requires a matching `password_confirmation` field. Laravel's
 * default password rules are used rather than a hand-rolled regex: they include
 * the compromised-password check when it is enabled, and a bespoke "one upper,
 * one digit" pattern is both weaker and something else to maintain.
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token'    => ['required', 'string'],
            'email'    => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
