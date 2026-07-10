<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SendTestSiteEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to'   => ['required', 'string', 'email', 'max:180'],
            // Optional — drives the "Dear {name}," greeting in the test email.
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
