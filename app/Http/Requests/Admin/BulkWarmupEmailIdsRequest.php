<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BulkWarmupEmailIdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_map('intval', $this->validated('ids'));
    }
}
