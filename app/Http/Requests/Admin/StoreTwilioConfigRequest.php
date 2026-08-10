<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\TwilioConfig;
use App\Support\Phone\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTwilioConfigRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],

            // Twilio Account SIDs are "AC" followed by 32 hex characters. Checked
            // strictly because the usual mistake — pasting an API Key SID ("SK…")
            // or a Messaging Service SID ("MG…") into this field — otherwise only
            // surfaces later as an opaque 401.
            'account_sid' => ['required', 'string', 'regex:/^AC[0-9a-f]{32}$/i'],

            'auth_token' => ['required', 'string', 'min:16', 'max:500'],

            // One sender identity is required, enforced in withValidator() since
            // it is either/or rather than a per-field rule.
            'from_number' => ['nullable', 'string', 'max:20'],
            'messaging_service_sid' => ['nullable', 'string', 'regex:/^MG[0-9a-f]{32}$/i'],

            'status' => ['sometimes', Rule::in(TwilioConfig::STATUSES)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'account_sid.regex' => 'The Account SID starts with "AC" and is 34 characters long. Copy it from the Twilio Console dashboard — not an API Key SID.',
            'messaging_service_sid.regex' => 'A Messaging Service SID starts with "MG" and is 34 characters long.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $number = trim((string) $this->input('from_number'));
            $service = trim((string) $this->input('messaging_service_sid'));

            // A credential with no sender cannot send anything. Rejecting it here
            // means the failure is one clear form error, not one Twilio 21603 per
            // recipient discovered halfway through a run.
            if ($number === '' && $service === '') {
                $validator->errors()->add(
                    'from_number',
                    'Enter either a Twilio phone number or a Messaging Service SID to send from.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        // The sending number must be E.164 too — Twilio rejects anything else,
        // and normalising here means the admin can paste it in whatever format
        // the console displayed.
        $number = $this->input('from_number');

        if (is_string($number) && trim($number) !== '') {
            $this->merge(['from_number' => PhoneNumber::normalise($number) ?? trim($number)]);
        }
    }
}
