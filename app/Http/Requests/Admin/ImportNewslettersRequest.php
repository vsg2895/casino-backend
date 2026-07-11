<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class ImportNewslettersRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already behind auth:sanctum.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            // Whether to mark imported subscribers as already verified. Optional;
            // defaults to false (unverified) when omitted.
            'verified' => ['nullable', 'boolean'],
            // Validate by client extension: xlsx MIME detection is unreliable
            // (it is a zip under the hood), so a mimes rule would reject valid files.
            'file' => [
                'required',
                'file',
                'max:5120', // 5 MB
                function (string $attribute, mixed $value, callable $fail): void {
                    $ext = $value instanceof UploadedFile
                        ? strtolower($value->getClientOriginalExtension())
                        : '';
                    if (! in_array($ext, ['xlsx', 'csv'], true)) {
                        $fail('The file must be an .xlsx or .csv spreadsheet.');
                    }
                },
            ],
        ];
    }
}
