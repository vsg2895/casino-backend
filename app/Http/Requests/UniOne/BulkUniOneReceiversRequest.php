<?php

declare(strict_types=1);

namespace App\Http\Requests\UniOne;

use App\Models\UniOne\UniOneReceiver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Bulk suppress / delete / set status over up to 5,000 receivers. */
class BulkUniOneReceiversRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['suppress', 'delete', 'status'])],
            'status' => ['required_if:action,status', Rule::in(UniOneReceiver::STATUSES)],
        ];
    }
}
