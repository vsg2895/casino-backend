<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The operator-profile spreadsheet.
 *
 * A much smaller cap than the receiver import: that file carries one row per
 * address and can legitimately be megabytes, while this one carries one row per
 * casino. Anything larger than a couple of megabytes is the wrong file.
 */
class ImportCasinoProfilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Upload the .xlsx or .csv you exported from this screen.',
            'file.max'   => 'That file is far larger than a profile sheet should be — check you picked the right one.',
        ];
    }
}
