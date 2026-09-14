<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Changing your own password while signed in.
 *
 * The current password is required even though the request already carries a
 * valid token. That is the whole point of the screen: a token is something a
 * stolen laptop, a borrowed session or an XSS payload can hold, and without
 * this field any of them could lock the real owner out of the panel forever.
 * Knowing the current password is the one thing those cases do not have.
 *
 * It is checked with an explicit Hash::check rather than Laravel's
 * `current_password` rule, and that is deliberate. That rule delegates to
 * `Auth::guard(...)->validate()`, and this route is behind `auth:sanctum`,
 * whose guard is a RequestGuard — its validate() resolves the user from the
 * request and NEVER looks at the password it is handed. The rule would pass for
 * any string an attacker typed. Verified against the framework source before
 * writing this, not assumed.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],

            /*
             * Password::defaults() is configured once in AppServiceProvider, so
             * this screen and the emailed-reset screen cannot drift apart on
             * what counts as a strong password. `confirmed` requires a matching
             * `password_confirmation`, which catches a typo that would otherwise
             * lock the admin out — there is no second account to recover with.
             */
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter your current password.',
            'password.confirmed'        => 'The new password and its confirmation do not match.',
        ];
    }

    /**
     * Checks that need the authenticated user, run only once the field-level
     * rules above have passed.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var User $user */
            $user = $this->user();

            $current = (string) $this->input('current_password');
            $new = (string) $this->input('password');

            if ($current !== '' && ! Hash::check($current, $user->password)) {
                $validator->errors()->add('current_password', 'That is not your current password.');

                return;
            }

            // Re-saving the same password is a no-op the operator would read as
            // a successful rotation — worse than an error, because it would end
            // their other sessions and make them believe a leaked password is
            // now dead when it is still live.
            if ($new !== '' && Hash::check($new, $user->password)) {
                $validator->errors()->add('password', 'The new password must be different from your current one.');
            }
        });
    }
}
