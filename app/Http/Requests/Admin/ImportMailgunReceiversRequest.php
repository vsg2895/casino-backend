<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Spreadsheet upload.
 *
 * `consent_source` applies to every row in the file. Required for the same
 * reason it is required on the manual form — an import is the fastest way to
 * add thousands of provenance-less addresses, so the gate belongs here most of all.
 */
class ImportMailgunReceiversRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:20480'],
            'consent_source' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.mimes'              => 'Upload an .xlsx or .csv file.',
            'consent_source.required' => 'Record where this list came from before importing it.',
        ];
    }
}
