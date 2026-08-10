<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class ImportNewsletterPhonesRequest extends FormRequest
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
            // Validated by client extension, not MIME: an .xlsx is a zip under
            // the hood, so a `mimes` rule rejects perfectly valid files.
            'file' => [
                'required',
                'file',
                // 20 MB, matching the subscriber import. PHP's
                // upload_max_filesize / post_max_size and the web server's
                // client_max_body_size must allow at least as much.
                'max:20480',
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
