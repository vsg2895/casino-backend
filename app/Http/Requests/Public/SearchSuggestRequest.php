<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the search-suggest endpoint.
 *
 * There is deliberately NO site parameter. The site is resolved by
 * VerifySiteAccess from the X-Site-Key header and bound into the container; a
 * client-supplied site id would let any key read another domain's index.
 */
class SearchSuggestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // min 1, not min 3: queries below the fulltext token size are served
            // by the prefix fallback rather than rejected.
            'q'       => ['required', 'string', 'min:1', 'max:100'],
            'section' => ['nullable', 'string', Rule::in(array_keys((array) config('search.sections', [])))],
            'page'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'q.required' => 'Type something to search for.',
            'q.max'      => 'That search is too long.',
            'section.in' => 'Unknown section.',
        ];
    }
}
