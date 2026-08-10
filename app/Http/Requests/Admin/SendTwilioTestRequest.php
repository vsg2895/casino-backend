<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\Phone\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an admin Twilio credential test: one message, to one number, through
 * the credential named by the route binding.
 *
 * The counterpart of the SendGrid / Mailgun key tests — the point is to prove
 * that THIS stored credential can actually deliver, before it is trusted with a
 * bulk run.
 */
class SendTwilioTestRequest extends FormRequest
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
            'to' => [
                'required',
                'string',
                'max:20',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (! PhoneNumber::isValid($value)) {
                        $fail('Enter the number in international format, including the country code (e.g. +15551234567).');
                    }
                },
            ],
            'body' => ['required', 'string', 'min:1', 'max:' . (int) config('sms.max_body_length', 1600)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to.required'   => 'Enter the number to send the test to.',
            'body.required' => 'Enter the message to send.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Normalised by the same rules as a stored number, so the test exercises
        // exactly what a real send would.
        $normalised = PhoneNumber::normalise($this->input('to'));

        if ($normalised !== null) {
            $this->merge(['to' => $normalised]);
        }
    }
}
