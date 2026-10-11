<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual creation of a single receiver.
 *
 * An address and an optional display name — nothing else. The row's own
 * timestamps record when it was added.
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
            // Where this address came from, in the operator's own words. The
            // same label an import stamps, so a hand-added address can join a
            // list rather than being invisible to every targeted send.
            'source_label'   => ['nullable', 'string', 'max:120'],
            // No `is_active`: the controller forces it true. A receiver is on
            // the list and mailed, or it is not on the list.
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique'            => 'This address is already on the receiver list.',
        ];
    }
}
