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
 * Covers the shared promotion fields (sender, hero, body, colours) PLUS this
 * template's own richer design — the eyebrow label, star-rating highlight box,
 * responsible-gambling notice, footer tagline + navigation links, affiliate
 * disclosure and copyright — and the settings half: the delay and the transport.
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
            // TRANSIENT: render this one preview against this site. Not a
            // column, and stripped before the unsaved template model is built.
            // Kept for backward compatibility with callers that still send it.
            'site_id'           => ['nullable', 'integer', 'exists:sites,id'],

            // PERSISTED: the site the admin picked, remembered so reopening the
            // editor does not reset it. Still only ever resolves the
            // {{site_name}} / {{site_url}} placeholders for preview and test —
            // the automatic send reads each subscriber's OWN site, never this.
            'preview_site_id'   => ['nullable', 'integer', 'exists:sites,id'],

            // ── Template (identical to the per-site promotion editor) ────
            'from_name'         => ['required', 'string', 'max:120'],
            'from_email'        => ['required', 'string', 'email', 'max:180'],
            'subject'           => ['required', 'string', 'max:200'],
            'unsubscribe_label' => ['required', 'string', 'max:80'],

            'preheader'         => ['nullable', 'string', 'max:250'],
            'hero_image_url'    => ['nullable', 'url', 'max:500'],
            'hero_url'          => ['nullable', 'string', 'max:500'],
            'top_button_text'   => ['nullable', 'string', 'max:80'],
            // Where the TOP button points. Plain string, not `url`, for the same
            // reason hero_url and cta_button_url are. Empty falls back to
            // hero_url, then the site.
            'top_button_url'    => ['nullable', 'string', 'max:500'],
            'cta_button_text'   => ['nullable', 'string', 'max:80'],
            // Where the CTA points. A plain string, not `url`, for the same
            // reason hero_url is: affiliate destinations carry tracking macros
            // and {{site_url}} placeholders that the `url` rule rejects.
            // Empty falls back to hero_url, then the site URL.
            'cta_button_url'    => ['nullable', 'string', 'max:500'],
            'heading'           => ['nullable', 'string', 'max:150'],
            'intro_text'        => ['nullable', 'string', 'max:1000'],
            // Intro styling. Bounds come from the model so this rule, the render
            // fallback and the admin input cannot disagree. Null for either means
            // "as before": default size, no panel.
            'intro_text_font_size' => [
                'nullable', 'integer',
                'min:' . VerificationPromotionEmail::INTRO_TEXT_MIN_SIZE,
                'max:' . VerificationPromotionEmail::INTRO_TEXT_MAX_SIZE,
            ],
            'intro_text_background_color' => ['nullable', 'string', $hex],

            // Button label size — ONE value for BOTH buttons, so they stay a
            // matched pair. Bounds from the model, same as the intro's.
            'button_text_font_size' => [
                'nullable', 'integer',
                'min:' . VerificationPromotionEmail::BUTTON_TEXT_MIN_SIZE,
                'max:' . VerificationPromotionEmail::BUTTON_TEXT_MAX_SIZE,
            ],
            'secondary_text'    => ['nullable', 'string', 'max:1000'],
            'disclaimer_text'   => ['nullable', 'string', 'max:1000'],

            // ── New design components ─────────────────────────────────────
            'header_brand_text'         => ['nullable', 'string', 'max:120'],
            'eyebrow_text'              => ['nullable', 'string', 'max:120'],

            // Which optional blocks are switched OFF. Visibility only — every
            // block's own text is stored separately and is never touched by
            // hiding it, which is what makes removal reversible.
            // `sometimes`, so a caller that omits the key leaves the stored
            // selection alone rather than un-hiding everything.
            'hidden_blocks'   => ['sometimes', 'array'],
            'hidden_blocks.*' => ['string', Rule::in(VerificationPromotionEmail::OPTIONAL_BLOCKS)],
            'confirmation_text'         => ['nullable', 'string', 'max:200'],
            'highlight_text'            => ['nullable', 'string', 'max:120'],
            'responsible_notice_text'   => ['nullable', 'string', 'max:1000'],

            // Footer legal / contact lines
            'reason_text'             => ['nullable', 'string', 'max:300'],
            'age_disclaimer_text'     => ['nullable', 'string', 'max:200'],
            'postal_address'          => ['nullable', 'string', 'max:300'],
            'contact_email'           => ['nullable', 'string', 'max:180'],
            'email_preferences_label' => ['nullable', 'string', 'max:60'],
            'email_preferences_url'   => ['nullable', 'string', 'max:300'],

            // Offer "ticket" terms — an ordered list of {label,value} columns.
            'offer_terms'         => ['nullable', 'array', 'max:6'],
            'offer_terms.*.label' => ['required_with:offer_terms', 'string', 'max:40'],
            'offer_terms.*.value' => ['required_with:offer_terms', 'string', 'max:40'],
            'footer_tagline'            => ['nullable', 'string', 'max:500'],
            'affiliate_disclosure_text' => ['nullable', 'string', 'max:500'],
            'copyright_text'            => ['nullable', 'string', 'max:200'],

            // Ordered list of {label,url} footer navigation links.
            'footer_links'          => ['nullable', 'array', 'max:8'],
            'footer_links.*.label'  => ['required_with:footer_links', 'string', 'max:60'],
            'footer_links.*.url'    => ['required_with:footer_links', 'string', 'max:300'],

            'button_color'            => ['required', 'string', $hex],
            'accent_color'            => ['required', 'string', $hex],
            'background_color'        => ['nullable', 'string', $hex],
            'body_background_color'   => ['nullable', 'string', $hex],
            'header_color'            => ['nullable', 'string', $hex],
            'heading_color'           => ['nullable', 'string', $hex],
            'text_color'              => ['nullable', 'string', $hex],
            'secondary_text_color'    => ['nullable', 'string', $hex],
            'muted_text_color'        => ['nullable', 'string', $hex],
            'footer_background_color' => ['nullable', 'string', $hex],
            'footer_text_color'       => ['nullable', 'string', $hex],
            'footer_link_color'       => ['nullable', 'string', $hex],

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
            'intro_text_font_size.min' => 'The intro text size must be at least '
                . VerificationPromotionEmail::INTRO_TEXT_MIN_SIZE . 'px.',
            'intro_text_font_size.max' => 'The intro text size cannot exceed '
                . VerificationPromotionEmail::INTRO_TEXT_MAX_SIZE . 'px.',
            'button_text_font_size.min' => 'The button text size must be at least '
                . VerificationPromotionEmail::BUTTON_TEXT_MIN_SIZE . 'px.',
            'button_text_font_size.max' => 'The button text size cannot exceed '
                . VerificationPromotionEmail::BUTTON_TEXT_MAX_SIZE . 'px.',
            'intro_text_background_color.regex' => 'The intro background must be a valid hex color (e.g. #f3f4f6).',
        ];
    }
}
