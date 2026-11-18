<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\EmailSchedule;
use Illuminate\Contracts\Validation\Validator;
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

            // WHICH email: one of the site's stored templates, or the
            // operator's own HTML typed below. Absent means the promotion
            // template, which is what every schedule sent before the choice
            // existed — see prepareForValidation().
            'template'      => ['required', Rule::in(EmailSchedule::TEMPLATES)],

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

            // Whether this schedule may mail addresses that opted out of its
            // site. Absent means NO, which is what every schedule does.
            'include_unsubscribed' => ['sometimes', 'boolean'],

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

            // ── Custom HTML only ──────────────────────────────────────────
            // Required together, because half of them is an email that cannot
            // be sent; forced to null for every other template in
            // prepareForValidation(), so switching template cannot leave a
            // stale body behind to be sent by accident.
            'custom_from_name'    => [
                'nullable',
                'required_if:template,' . EmailSchedule::TEMPLATE_CUSTOM,
                'string', 'max:120',
            ],
            // A real address, and the ONE field here that can quietly destroy
            // deliverability: a domain the sending account is not authorised
            // for fails SPF/DKIM alignment and is filtered rather than bounced.
            // Nothing server-side can verify that, so the admin form says it.
            'custom_from_address' => [
                'nullable',
                'required_if:template,' . EmailSchedule::TEMPLATE_CUSTOM,
                'email:rfc', 'max:255',
            ],
            'custom_subject'      => [
                'nullable',
                'required_if:template,' . EmailSchedule::TEMPLATE_CUSTOM,
                'string', 'max:' . self::maxSubjectLength(),
            ],
            // Byte-bounded in withValidator(): `max` on a string counts
            // CHARACTERS, and an HTML body is measured in bytes on the wire.
            'custom_html'         => [
                'nullable',
                'required_if:template,' . EmailSchedule::TEMPLATE_CUSTOM,
                'string',
            ],

            'active'        => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Cross-field rules that read better as one sentence than as a rule
     * string: the byte ceiling on the HTML, and the one template that only
     * makes sense for the site's own subscribers.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $template = $this->input('template');

            if ($template === EmailSchedule::TEMPLATE_CUSTOM) {
                $html = $this->input('custom_html');
                $maxBytes = (int) config('promotions.custom.max_html_bytes', 500_000);

                if (is_string($html) && strlen($html) > $maxBytes) {
                    $validator->errors()->add('custom_html', sprintf(
                        'The HTML is %s KB; the limit is %s KB.',
                        number_format(strlen($html) / 1000, 1),
                        number_format($maxBytes / 1000),
                    ));
                }
            }

            // The verify email is the one template a global list cannot be
            // sent: it asks for confirmation of an address the recipient
            // submitted, and nobody on an operator-built list submitted one.
            // Refused at save time so the schedule cannot exist in a shape the
            // send path would have to throw on.
            //
            // The subscription email used to be refused here too, because its
            // opt-out link was resolved from a subscriber's own token. Every
            // global list now carries its own, so that pair is valid — see
            // EmailSchedule::SUBSCRIBER_ONLY_TEMPLATES.
            if (
                in_array($template, EmailSchedule::SUBSCRIBER_ONLY_TEMPLATES, true)
                && $this->input('audience') !== EmailSchedule::AUDIENCE_NEWSLETTERS
            ) {
                $validator->errors()->add('audience', sprintf(
                    'The %s can only be sent to Newsletters — the addresses on a global list have nothing to confirm.',
                    EmailSchedule::TEMPLATE_LABELS[$template] ?? $template,
                ));
            }
        });
    }

    /** Subject ceiling, shared with the admin form's counter. */
    private static function maxSubjectLength(): int
    {
        return (int) config('promotions.custom.max_subject_length', 255);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sendgrid_key_id.required_if' => 'Select a SendGrid key to send through.',
            'sendgrid_key_id.exists'      => 'The selected SendGrid key is not available (inactive or removed).',
            'mailgun_key_id.required_if'  => 'Select a Mailgun key to send through.',
            'mailgun_key_id.exists'       => 'The selected Mailgun key is not available (inactive or removed).',
            'template.required'           => 'Choose which email this schedule sends.',
            'template.in'                 => 'That template cannot be sent by a schedule.',
            'custom_from_name.required_if'    => 'Enter the name the custom email is sent from.',
            'custom_from_address.required_if' => 'Enter the address the custom email is sent from.',
            'custom_from_address.email'       => 'Enter a valid From address for the custom email.',
            'custom_subject.required_if'      => 'Enter a subject for the custom email.',
            'custom_html.required_if'         => 'Paste the HTML for the custom email.',
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

        // The template defaults to the promotion email: what every schedule
        // sent before the choice existed, and what a client that predates the
        // field still means.
        $template = in_array($this->template, EmailSchedule::TEMPLATES, true)
            ? $this->template
            : EmailSchedule::TEMPLATE_PROMOTION;

        $isCustom = $template === EmailSchedule::TEMPLATE_CUSTOM;

        $this->merge([
            'template'        => $template,
            // Only the custom template keeps its own content. Switching away
            // clears it, so a stored template send can never carry a body the
            // admin stopped meaning to send.
            'custom_from_name'    => $isCustom ? $this->custom_from_name : null,
            'custom_from_address' => $isCustom ? $this->custom_from_address : null,
            'custom_subject'      => $isCustom ? $this->custom_subject : null,
            'custom_html'         => $isCustom ? $this->custom_html : null,
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
