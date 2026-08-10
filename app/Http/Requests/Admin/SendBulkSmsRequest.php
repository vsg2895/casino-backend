<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\Phone\PhoneAudienceFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Starting a bulk SMS run.
 *
 * Validates the credential, the message and the audience filters together,
 * because they are one decision: an admin is saying "send THIS text to THESE
 * numbers through THAT account". The filters are validated to the same vocabulary
 * {@see PhoneAudienceFilter} understands, so an accepted request can always be
 * turned into a real audience.
 */
class SendBulkSmsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Must exist AND be active: sending through a credential the admin has
            // disabled is never what they meant.
            'twilio_config_id' => [
                'required',
                'integer',
                Rule::exists('twilio_configs', 'id')->where('status', 'active'),
            ],

            'body' => [
                'required',
                'string',
                'min:1',
                'max:' . (int) config('sms.max_body_length', 1600),
            ],

            // Audience filters — all optional; absent means "everyone currently
            // subscribed".
            'mode'      => ['nullable', 'string', Rule::in(PhoneAudienceFilter::MODES)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'limit'     => ['nullable', 'integer', 'min:1'],
            'search'    => ['nullable', 'string', 'max:32'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'twilio_config_id.exists' => 'Choose an active Twilio configuration to send through.',
            'date_to.after_or_equal'  => 'The end date cannot be earlier than the start date.',
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            $mode = $this->input('mode');

            // A date mode with no date would silently resolve to "no filter" and
            // message the entire list. Refuse it at the door instead.
            $needsDate = [
                PhoneAudienceFilter::MODE_ON,
                PhoneAudienceFilter::MODE_BEFORE,
                PhoneAudienceFilter::MODE_AFTER,
                PhoneAudienceFilter::MODE_RANGE,
            ];

            if (in_array($mode, $needsDate, true) && $this->input('date_from') === null) {
                $validator->errors()->add('date_from', 'Choose a date for the selected filter.');
            }
        });
    }

    /** The audience filters as the value object every consumer downstream uses. */
    public function audienceFilter(): PhoneAudienceFilter
    {
        return PhoneAudienceFilter::fromArray($this->validated());
    }

    public function body(): string
    {
        // Trimmed, because trailing whitespace in an SMS can push it over a
        // 160-character segment boundary and silently double the bill.
        return trim((string) $this->validated('body'));
    }
}
