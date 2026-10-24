<?php

declare(strict_types=1);

namespace App\Http\Requests\UniOne;

use Illuminate\Foundation\Http\FormRequest;

/** A one-address test send through the real send path. */
class TestUniOneSendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'unione_api_key_id' => ['required', 'integer'],
            'email' => ['required', 'email'],
            'subject' => ['nullable', 'string', 'max:255'],
            'from_email' => ['required', 'email'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'reply_to' => ['nullable', 'email'],
        ];
    }
}
