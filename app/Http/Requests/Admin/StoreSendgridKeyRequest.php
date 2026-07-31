<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SendgridKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSendgridKeyRequest extends FormRequest
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
            'name'    => ['required', 'string', 'max:120'],
            // SendGrid keys look like "SG.xxxx.yyyy"; keep the check loose but
            // reject obviously-empty/short values.
            'api_key' => ['required', 'string', 'min:20', 'max:500'],
            'status'  => ['sometimes', Rule::in(SendgridKey::STATUSES)],
        ];
    }
}
