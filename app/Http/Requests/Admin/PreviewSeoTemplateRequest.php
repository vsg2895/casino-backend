<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** One pattern to render against sample values. */
class PreviewSeoTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pattern' => ['required', 'string', 'max:500'],
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
