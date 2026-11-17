<?php

declare(strict_types=1);

namespace App\Perfume\Http\Requests;

class SubscribeRequest extends PerfumeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'name'  => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['email.email' => 'That does not look like an email address.'];
    }
}
