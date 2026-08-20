<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\EmailSchedule;
use App\Models\VerificationPromotionEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for the global post-verification promotion.
 *
 * The template half mirrors {@see UpdateSitePromotionEmailRequest} field for
 * field — same columns, same rules, so an admin who knows one editor knows this
 * one. What this request adds is the settings half: the delay and the transport.
 */
class UpdateVerificationPromotionEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already behind auth:sanctum.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $hex = 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

        return [
            // ── Template (identical to the per-site promotion editor) ────
            'from_name'         => ['required', 'string', 'max:120'],
            'from_email'        => ['required', 'string', 'email', 'max:180'],
            'subject'           => ['required', 'string', 'max:200'],
            'unsubscribe_label' => ['required', 'string', 'max:80'],

            'preheader'         => ['nullable', 'string', 'max:250'],
            'hero_image_url'    => ['nullable', 'url', 'max:500'],
            'hero_url'          => ['nullable', 'string', 'max:500'],
            'top_button_text'   => ['nullable', 'string', 'max:80'],
            'cta_button_text'   => ['nullable', 'string', 'max:80'],
            'heading'           => ['nullable', 'string', 'max:150'],
            'intro_text'        => ['nullable', 'string', 'max:1000'],
            'secondary_text'    => ['nullable', 'string', 'max:1000'],
            'disclaimer_text'   => ['nullable', 'string', 'max:1000'],

            'button_color'         => ['required', 'string', $hex],
            'accent_color'         => ['required', 'string', $hex],
            'background_color'     => ['nullable', 'string', $hex],
            'heading_color'        => ['nullable', 'string', $hex],
            'text_color'           => ['nullable', 'string', $hex],
            'secondary_text_color' => ['nullable', 'string', $hex],
            'muted_text_color'     => ['nullable', 'string', $hex],

            // ── Settings ─────────────────────────────────────────────────
            'active' => ['required', 'boolean'],

            // Whole minutes, never negative, capped at 30 days. `integer`
            // rejects "30.5" and "abc"; min:0 permits an intentional
            // send-as-soon-as-verified setup while making a negative delay
            // (which would back-date eligibility and fire immediately for the
            // entire existing list) impossible.
            'delay_minutes' => ['required', 'integer', 'min:0', 'max:' . VerificationPromotionEmail::MAX_DELAY_MINUTES],

            // Transport, resolved through the same factory scheduled campaigns
            // use — but from THIS feature's own option list: SendGrid here means
            // the .env key and takes no stored credential.
            'provider'        => ['required', 'string', Rule::in(VerificationPromotionEmail::PROVIDERS)],
            'sendgrid_key_id' => ['nullable', 'integer', 'exists:sendgrid_keys,id'],
            'mailgun_key_id'  => ['nullable', 'integer', 'exists:mailgun_keys,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $provider = (string) $this->input('provider');

            // Which stored credential (if any) this provider needs. SMTP and
            // SENDGRID_ENV are both configured entirely from .env, so neither
            // appears in the map and neither demands a selection.
            $column = EmailSchedule::PROVIDER_CREDENTIAL_COLUMNS[$provider] ?? null;

            // A keyed provider without a key would resolve to nothing at send
            // time and silently deliver zero mail. Caught here instead, while
            // someone is looking at the screen.
            if ($column !== null && $this->input($column) === null) {
                $validator->errors()->add($column, 'Select a ' . $provider . ' key to send this promotion with.');
            }

            // Enabling the feature is the point of no return — everything has to
            // be in place before subscribers start receiving mail.
            if ($this->boolean('active') && $column !== null && $this->input($column) === null) {
                $validator->errors()->add('active', 'Configure a sending key before enabling the promotion.');
            }

            // The .env-key option is just as unusable when the environment is
            // unset. Fail on the screen rather than once per subscriber later.
            if (
                $provider === EmailSchedule::PROVIDER_SENDGRID_ENV
                && $this->boolean('active')
                && trim((string) config('mail.mailers.sendgrid.key', '')) === ''
            ) {
                $validator->errors()->add(
                    'provider',
                    'SENDGRID_API_KEY is not set on this server, so the promotion cannot be enabled with the .env SendGrid key.',
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'delay_minutes.integer' => 'The delay must be a whole number of minutes.',
            'delay_minutes.min'     => 'The delay cannot be negative.',
            'delay_minutes.max'     => 'The delay cannot exceed 30 days (43200 minutes).',
            'button_color.regex'    => 'The button color must be a valid hex color (e.g. #75B636).',
            'accent_color.regex'    => 'The accent color must be a valid hex color (e.g. #f3a333).',
        ];
    }
}
