<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asking for a password reset link.
 *
 * Note what is NOT here: an `exists:users,email` rule. It would reject an
 * unknown address with a validation error and turn the endpoint into an
 * account-enumeration oracle — try addresses, see which ones validate. The
 * controller answers identically either way; only the format is checked.
 */
class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }
}
