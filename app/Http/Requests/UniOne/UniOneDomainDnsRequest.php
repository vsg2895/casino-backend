<?php

declare(strict_types=1);

namespace App\Http\Requests\UniOne;

use Illuminate\Foundation\Http\FormRequest;

/** The domain whose DNS records UniOne should report. */
class UniOneDomainDnsRequest extends FormRequest
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
        ];
    }
}
