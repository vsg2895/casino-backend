<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A bulk action over receivers. Delete is the only one: see
 * MailgunReceiverController::bulk() for why activate/deactivate were removed.
 */
class BulkMailgunReceiversRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:delete'],
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['integer'],
        ];
    }
}
