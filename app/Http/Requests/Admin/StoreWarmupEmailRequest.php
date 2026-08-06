<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarmupEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already behind auth:sanctum.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('warmup_emails', 'email')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['email.unique' => 'That address is already on the warmup list.'];
    }

    protected function prepareForValidation(): void
    {
        // Stored lowercase so the unique index actually prevents duplicates —
        // MySQL's default collation is case-insensitive, but normalising here
        // keeps the data consistent and matches how the importer writes.
        $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
    }
}
