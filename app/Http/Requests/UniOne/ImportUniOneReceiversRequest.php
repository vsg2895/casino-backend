<?php

declare(strict_types=1);

namespace App\Http\Requests\UniOne;

use Illuminate\Foundation\Http\FormRequest;

/** A spreadsheet of receivers: .xlsx or .csv with an Email column. */
class ImportUniOneReceiversRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // 20 MB matches the warmup import's ceiling; xlsx of this size is
            // already hundreds of thousands of rows.
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:20480'],
        ];
    }
}
