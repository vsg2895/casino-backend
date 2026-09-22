<?php

declare(strict_types=1);

namespace App\Http\Requests\Public\Forum;

use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterForumUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Site $site */
        $site = app('current_site');

        return [
            'display_name' => ['required', 'string', 'min:2', 'max:60'],
            'email'        => [
                'required',
                /*
                 * The DNS check runs in production only.
                 *
                 * `dns` resolves an MX record, which is a genuinely useful spam
                 * filter against typo and throwaway domains — and which refuses
                 * every reserved test domain, so it would make the seeders and
                 * the test suite unable to create an account. Gated on the
                 * environment exactly as `Password::defaults()` gates
                 * `uncompromised()` in AppServiceProvider.
                 */
                app()->isProduction() ? 'email:rfc,dns' : 'email:rfc',
                'max:255',
                // Scoped to the site, because accounts are per-site. A global
                // unique rule would refuse a legitimate registration on a second
                // domain and leak that the address exists on the first.
                Rule::unique('forum_users', 'email')->where('site_id', $site->id),
            ],
            // The platform's shared policy — see AppServiceProvider, which sets
            // 12 characters, mixed case, numbers, symbols, and `uncompromised()`
            // in production.
            'password'     => ['required', 'confirmed', Password::defaults()],
            // The honeypot. A real browser never fills this; a bot fills every
            // input it finds. Must be PRESENT and EMPTY — a missing field means
            // the form was not the one we served.
            'website'      => ['present', 'max:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique'   => 'There is already an account with that email address on this site.',
            'website.max'    => 'That submission looked automated.',
            'website.present' => 'That submission looked automated.',
        ];
    }
}
