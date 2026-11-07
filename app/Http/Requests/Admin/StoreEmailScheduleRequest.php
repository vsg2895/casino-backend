<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\EmailSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmailScheduleRequest extends FormRequest
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
            'site_id'       => ['required', 'integer', 'exists:sites,id'],
            'name'          => ['nullable', 'string', 'max:120'],

            // WHICH list: the site's newsletters, a Custom Receivers list, or warmup.
            'audience'      => ['required', Rule::in(EmailSchedule::AUDIENCES)],
            // How many addresses one run takes, newest first.
            'limit'         => ['required', 'integer', 'min:1', 'max:' . EmailSchedule::MAX_LIMIT],
            // Skip anyone who received this site's promotion within N days.
            'cooldown_days' => [
                'required', 'integer',
                'min:' . EmailSchedule::MIN_COOLDOWN_DAYS,
                'max:' . EmailSchedule::MAX_COOLDOWN_DAYS,
            ],

            'frequency'     => ['required', Rule::in(EmailSchedule::FREQUENCIES)],
            'time'          => ['required', 'date_format:H:i'],
            'day_of_week'   => [
                'nullable',
                'required_if:frequency,' . EmailSchedule::FREQ_WEEKLY,
                'integer', 'between:0,6',
            ],
            'day_of_month'  => [
                'nullable',
                'required_if:frequency,' . EmailSchedule::FREQ_MONTHLY,
                'integer', 'between:1,31',
            ],

            // Delivery transport. SMTP (default) uses the .env credentials as
            // before; SendGrid requires an ACTIVE stored key — an inactive or
            // missing key fails validation so a broken schedule can't be saved.
            'provider'        => ['required', Rule::in(EmailSchedule::PROVIDERS)],
            'sendgrid_key_id' => [
                'nullable',
                'required_if:provider,' . EmailSchedule::PROVIDER_SENDGRID,
                'integer',
                Rule::exists('sendgrid_keys', 'id')->where('status', 'active'),
            ],
            // Mailgun mirrors SendGrid exactly: required only when its provider
            // is selected, and the credential must still be ACTIVE, so a broken
            // schedule cannot be saved in the first place.
            'mailgun_key_id'  => [
                'nullable',
                'required_if:provider,' . EmailSchedule::PROVIDER_MAILGUN,
                'integer',
                Rule::exists('mailgun_keys', 'id')->where('status', 'active'),
            ],

            'active'        => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sendgrid_key_id.required_if' => 'Select a SendGrid key to send through.',
            'sendgrid_key_id.exists'      => 'The selected SendGrid key is not available (inactive or removed).',
            'mailgun_key_id.required_if'  => 'Select a Mailgun key to send through.',
            'mailgun_key_id.exists'       => 'The selected Mailgun key is not available (inactive or removed).',
            'limit.required'              => 'Set how many emails one run sends.',
            'cooldown_days.required'      => 'Set how many days an address rests after receiving this email.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Null out fields irrelevant to the chosen cadence so stale values from
        // the UI don't linger. The audience defaults to newsletters for a client
        // that predates it — the list every schedule used to mail.
        // Default the provider to SMTP for back-compat with any client that
        // doesn't send it, and drop a stale sendgrid_key_id when on SMTP.
        $provider = in_array($this->provider, EmailSchedule::PROVIDERS, true)
            ? $this->provider
            : EmailSchedule::PROVIDER_SMTP;

        $this->merge([
            'audience'        => $this->audience ?? EmailSchedule::AUDIENCE_NEWSLETTERS,
            'day_of_week'     => $this->frequency === EmailSchedule::FREQ_WEEKLY ? $this->day_of_week : null,
            'day_of_month'    => $this->frequency === EmailSchedule::FREQ_MONTHLY ? $this->day_of_month : null,
            'provider'        => $provider,
            // Each provider keeps only its own credential; switching provider
            // clears the other, so a stale id can never be resolved at send time.
            'sendgrid_key_id' => $provider === EmailSchedule::PROVIDER_SENDGRID ? $this->sendgrid_key_id : null,
            'mailgun_key_id'  => $provider === EmailSchedule::PROVIDER_MAILGUN ? $this->mailgun_key_id : null,
        ]);
    }
}
