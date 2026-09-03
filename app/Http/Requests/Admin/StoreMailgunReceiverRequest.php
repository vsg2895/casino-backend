<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual creation of a single receiver.
 *
 * `consent_source` is REQUIRED, not optional: an address with no recorded
 * provenance cannot lawfully be bulk-mailed, and making the field nullable is
 * how a list quietly ends up with rows nobody can account for.
 */
class StoreMailgunReceiverRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Uniqueness ignores soft deletes on purpose: a deleted row still
            // holds the unique index, so allowing a "new" duplicate here would
            // fail at the database instead of showing a field error.
            'email' => [
                'required', 'string', 'email:rfc', 'max:255',
                Rule::unique('mailgun_receivers', 'email'),
            ],
            'name'           => ['nullable', 'string', 'max:255'],
            'consent_source' => ['required', 'string', 'max:255'],
            // No `is_active`: the controller forces it true. A receiver is on
            // the list and mailed, or it is not on the list.
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'consent_source.required' => 'Record where this address came from — it cannot be left blank.',
            'email.unique'            => 'This address is already on the receiver list.',
        ];
    }
}
