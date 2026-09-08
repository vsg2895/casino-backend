<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a country.
 *
 * `code` is optional and NOT unique: the grid carries entries that are not
 * countries — a Europe-wide card, an "Arab" card — and those have no ISO code
 * to give. The slug is generated from the name and never accepted from the
 * client, because it is part of the public URL.
 */
class StoreCountryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'continent_id' => ['required', 'integer', 'exists:continents,id'],
            'name'         => ['required', 'string', 'max:255', Rule::unique('countries', 'name')],
            'code'         => ['nullable', 'string', 'size:2', 'alpha'],
            'image_path'   => ['nullable', 'string', 'max:2048'],
            'position'     => ['nullable', 'integer', 'min:0', 'max:65535'],
            'active'       => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper((string) $this->input('code'))]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'A country with this name already exists.',
            'code.size'   => 'Use the two-letter ISO code, e.g. DE — or leave it empty.',
        ];
    }
}
