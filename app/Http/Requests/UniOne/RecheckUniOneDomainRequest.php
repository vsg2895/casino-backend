<?php

declare(strict_types=1);

namespace App\Http\Requests\UniOne;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Ask UniOne to re-validate one DNS record of a domain. */
class RecheckUniOneDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:255'],
            'check' => ['required', Rule::in(['dkim', 'verification'])],
        ];
    }
}
