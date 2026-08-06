<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class ImportWarmupEmailsRequest extends FormRequest
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
            // Validate by client extension: xlsx MIME detection is unreliable
            // (it is a zip under the hood), so a mimes rule would reject valid files.
            'file' => [
                'required',
                'file',
                // 20 MB — a 5 MB cap rejected lists of a few hundred thousand
                // addresses, which the batched importer handles fine. PHP's
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
