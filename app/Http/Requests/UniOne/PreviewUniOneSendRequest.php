<?php

declare(strict_types=1);

namespace App\Http\Requests\UniOne;

use Illuminate\Foundation\Http\FormRequest;

/** How many receivers a run of this size would reach. */
class PreviewUniOneSendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'count' => ['required', 'integer', 'min:1', 'max:100000'],
            'cooldown_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }
}
