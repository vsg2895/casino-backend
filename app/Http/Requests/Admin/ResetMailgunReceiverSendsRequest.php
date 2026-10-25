<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** The whole-list send-state reset; optionally clears last errors too. */
class ResetMailgunReceiverSendsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clear_errors' => ['sometimes', 'boolean'],
        ];
    }
}
