<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\Mail\ReceiverSource;
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
                Rule::unique($this->receiverTable(), 'email')->ignore($this->route($this->routeParameter())),
            ],
            'name'           => ['nullable', 'string', 'max:255'],
            // Editable: an address filed under the wrong list should be moved,
            // not deleted and re-imported.
            'source_label'   => ['sometimes', 'required', Rule::enum(ReceiverSource::class)],
            // No `is_active`: the controller forces it true. A receiver is on
            // the list and mailed, or it is not on the list.
        ];
    }

    /** The list this request writes to. Overridden by the SendGrid twin. */
    protected function receiverTable(): string
    {
        return 'mailgun_receivers';
    }

    /** The route parameter holding the receiver being edited. */
    protected function routeParameter(): string
    {
        return 'mailgun_receiver';
    }
}
