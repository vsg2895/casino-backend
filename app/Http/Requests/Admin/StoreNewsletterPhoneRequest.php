<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\Phone\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding one number to the phone list.
 *
 * The value is normalised to E.164 BEFORE validation runs, which is what makes
 * the `unique` rule and the unique index agree: without it, "+1 555 010 0199"
 * would pass a uniqueness check against a stored "+15550100199" and then collide
 * at insert time with a 500 instead of a field error.
 */
class StoreNewsletterPhoneRequest extends FormRequest
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
            'phone' => [
                'required',
                'string',
                'max:20',
                // Runs against the already-normalised value, so it only rejects
                // input that could not be made into E.164 at all.
                function (string $attribute, mixed $value, callable $fail): void {
                    if (! PhoneNumber::isValid($value)) {
                        $fail($this->invalidNumberMessage());
                    }
                },
                Rule::unique('newsletters_based_on_phone', 'phone'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.unique' => 'That number is already on the list.'];
    }

    protected function prepareForValidation(): void
    {
        $normalised = PhoneNumber::normalise($this->input('phone'));

        // Only replace the input when normalisation succeeded. Leaving an
        // unnormalisable value in place keeps the original in the error response,
        // so the admin sees what they typed rather than a blank field.
        if ($normalised !== null) {
            $this->merge(['phone' => $normalised]);
        }
    }

    /**
     * Spelling out the requirement, because "invalid" is not actionable: the
     * usual cause is a number with no country code, and whether that is even
     * fixable depends on config('sms.default_country_code').
     */
    protected function invalidNumberMessage(): string
    {
        $default = (string) config('sms.default_country_code', '');

        return $default === ''
            ? 'Enter the number in international format, including the country code (e.g. +15551234567).'
            : 'That does not look like a valid phone number. Include the country code (e.g. +' . $default . '…) if it is not a local number.';
    }
}
