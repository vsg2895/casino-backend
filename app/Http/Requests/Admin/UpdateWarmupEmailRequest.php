<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarmupEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Ignore this row, or saving an unchanged address would collide
                // with itself.
                Rule::unique('warmup_emails', 'email')->ignore($this->route('warmup_email')),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['email.unique' => 'That address is already on the warmup list.'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
    }
}
