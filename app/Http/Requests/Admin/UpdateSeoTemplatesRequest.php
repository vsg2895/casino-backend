<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SeoTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The whole set of a site's SEO patterns, saved at once. */
class UpdateSeoTemplatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'templates' => ['required', 'array', 'max:20'],
            'templates.*.entity' => ['required', Rule::in(SeoTemplate::ENTITIES)],
            'templates.*.title_pattern' => ['nullable', 'string', 'max:255'],
            'templates.*.description_pattern' => ['nullable', 'string', 'max:500'],
        ];
    }
}
