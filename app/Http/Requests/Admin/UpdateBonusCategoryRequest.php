<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBonusCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            /*
             * The stored PATH from the SVG upload endpoint, not a file.
             *
             * The icon goes up on its own request, is sanitised there and
             * written to the public disk; this field only records where it
             * landed. Explicit null clears it, which is how an editor removes
             * an icon without deleting the type.
             */
            'icon_path'   => ['nullable', 'string', 'max:255'],
            'position'    => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'active'      => ['sometimes', 'boolean'],
        ];
    }
}
