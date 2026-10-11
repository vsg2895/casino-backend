<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\Mail\ReceiverSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
             * Whose list this file is — ours or somebody else's. Optional only
             * because the column has a database default: an import that says
             * nothing is treated as `our_clients`, the same as every address
             * that predates the field.
             */
            'source_label' => ['sometimes', 'required', Rule::enum(ReceiverSource::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.mimes'              => 'Upload an .xlsx or .csv file.',
            'source_label.Illuminate\\Validation\\Rules\\Enum' => 'Choose either our clients or external clients.',
        ];
    }
}
