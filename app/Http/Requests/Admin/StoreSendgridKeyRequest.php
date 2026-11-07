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
            // The sender of receiver campaigns, which have no site template
            // to take one from. Must be a verified sender in SendGrid. Optional:
            // schedules and the test button never read it.
            'from_address' => ['nullable', 'email:rfc', 'max:255'],
            'from_name'    => ['nullable', 'string', 'max:120'],
            // The Signed Event Webhook's verification key, pasted from SendGrid.
            // A public key, so no masking; the format is checked when an event
            // arrives, and a key that cannot be parsed simply verifies nothing.
            'webhook_verification_key' => ['nullable', 'string', 'max:2000'],
            'status'  => ['sometimes', Rule::in(SendgridKey::STATUSES)],
        ];
    }
}
