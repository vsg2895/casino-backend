<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The complete category order, as one list of ids.
 *
 * `distinct` and `exists` are what keep a reorder from ever leaving duplicate
 * or dangling positions; see CategoryController::reorder().
 */
class ReorderCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct', 'exists:categories,id'],
        ];
    }
}
