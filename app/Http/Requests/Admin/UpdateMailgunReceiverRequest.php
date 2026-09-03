<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Editing an existing receiver. Same rules, ignoring this row's own address. */
class UpdateMailgunReceiverRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'string', 'email:rfc', 'max:255',
                Rule::unique('mailgun_receivers', 'email')->ignore($this->route('mailgun_receiver')),
            ],
            'name'           => ['nullable', 'string', 'max:255'],
            'consent_source' => ['required', 'string', 'max:255'],
            // No `is_active`: the controller forces it true. A receiver is on
            // the list and mailed, or it is not on the list.
        ];
    }
}
