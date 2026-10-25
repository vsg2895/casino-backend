<?php

declare(strict_types=1);

namespace App\Http\Requests\UniOne;

use App\Models\UniOne\UniOneReceiver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A UniOne receiver. The address is unique among live rows; on update the row
 * being edited is ignored, see UpdateUniOneReceiverRequest.
 */
class StoreUniOneReceiverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('unione_receivers', 'email')->ignore($this->ignoredReceiverId())->whereNull('deleted_at'),
            ],
            'name' => ['nullable', 'string', 'max:160'],
            'status' => ['sometimes', Rule::in(UniOneReceiver::STATUSES)],
            // Optional metadata now. Kept on the editor so an operator who DOES
            // have a consent record can still put it on the row — the send path
            // no longer requires it.
            'consent_source' => ['nullable', 'string', 'max:120'],
            'consent_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function ignoredReceiverId(): ?int
    {
        return null;
    }
}
