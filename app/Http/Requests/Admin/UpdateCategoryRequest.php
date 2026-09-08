<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'       => ['sometimes', 'required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            // The path returned by POST /admin/uploads/category-logo. A path and
            // not a file: the upload is its own request, so a validation failure
            // on the name never discards an already-uploaded logo. Explicit null
            // clears it.
            'logo_path'  => ['nullable', 'string', 'max:500'],
        ];
    }
}
