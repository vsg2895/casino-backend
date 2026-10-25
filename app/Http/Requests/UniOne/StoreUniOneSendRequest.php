<?php

declare(strict_types=1);

namespace App\Http\Requests\UniOne;

use Illuminate\Foundation\Http\FormRequest;

/** A UniOne campaign run. */
class StoreUniOneSendRequest extends FormRequest
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
            'count' => ['required', 'integer', 'min:1', 'max:100000'],
            'cooldown_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            // The template supplies the subject when this is left blank.
            'subject' => ['nullable', 'string', 'max:255'],
            'from_email' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'reply_to' => ['nullable', 'email', 'max:255'],
        ];
    }
}
