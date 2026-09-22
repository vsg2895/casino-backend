<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNewsCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'     => ['sometimes', 'string', 'max:120'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'active'   => ['sometimes', 'boolean'],
        ];
    }
}
