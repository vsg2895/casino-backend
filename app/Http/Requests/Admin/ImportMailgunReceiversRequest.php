<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Spreadsheet upload.
 *
 * The file is the whole payload. Its size cap matters more than it looks: the
 * upload is staged to disk and only its id is queued, but a 20 MB ceiling is
 * what keeps one request from filling the disk before the job ever runs.
 */
class ImportMailgunReceiversRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:20480'],
            /*
             * One label for every address in this file — "Facebook ads",
             * "Partner list 3". Optional: an unlabelled import is still a
             * perfectly good import, and those rows stay reachable because a
             * credential can target "no source" explicitly.
             */
            'source_label' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.mimes'              => 'Upload an .xlsx or .csv file.',
            'source_label.max'        => 'A source name may be at most 120 characters.',
        ];
    }
}
