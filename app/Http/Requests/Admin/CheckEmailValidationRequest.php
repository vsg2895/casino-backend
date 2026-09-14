<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The admin's ad-hoc "check one address" tool.
 *
 * A site is required, not optional: this call spends a real credit from the
 * shared monthly budget, and an unattributed row would show up in the quota
 * total while appearing in no site's column — which is exactly the kind of gap
 * that makes a budget impossible to reconcile.
 */
class CheckEmailValidationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Same syntax gate the public form applies, so an obviously
            // malformed address never reaches a paid endpoint.
            'email'   => ['required', 'string', 'email:rfc', 'max:255'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
        ];
    }
}
