<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SiteVerifyEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSiteVerifyEmailRequest extends FormRequest
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
            'from_name'         => ['required', 'string', 'max:120'],
            'from_email'        => ['required', 'string', 'email', 'max:180'],
            'subject'           => ['required', 'string', 'max:200'],
            'header_title'      => ['nullable', 'string', 'max:150'],
            'header_subtitle'   => ['nullable', 'string', 'max:250'],
            'heading'           => ['nullable', 'string', 'max:150'],
            'intro_text'        => ['nullable', 'string', 'max:1000'],
            'offer_text'        => ['nullable', 'string', 'max:1000'],
            'spam_notice'       => ['nullable', 'string', 'max:1000'],
            'footer_note'       => ['nullable', 'string', 'max:1000'],
            // Footer identity — a physical address and a monitored reply mailbox.
            'postal_address'    => ['nullable', 'string', 'max:300'],
            'contact_email'     => ['nullable', 'string', 'max:180'],

            // Which optional blocks are switched OFF. Visibility only — each keeps
            // its own text, which is what makes removal reversible.
            'hidden_blocks'   => ['sometimes', 'array'],
            'hidden_blocks.*' => ['string', Rule::in(SiteVerifyEmail::OPTIONAL_BLOCKS)],

            'unsubscribe_label' => ['required', 'string', 'max:80'],
            // `sometimes`, not `required`: an existing caller that does not send
            // the key leaves the stored value alone and keeps its current output.
            // The label above stays required either way, so removing the link
            // never discards the wording needed to restore it.
            'unsubscribe_enabled' => ['sometimes', 'boolean'],
            'copyright_text'    => ['nullable', 'string', 'max:200'],
            // Button label size. Bounds come from the model so this rule, the
            // render fallback and the admin input cannot disagree. Null means
            // "as before": the size this template has always rendered at.
            // NULLABLE, not required: clearing it restores the default caption
            // rather than rejecting the save or shipping an empty button.
            'verify_button_text' => ['nullable', 'string', 'max:80'],
            'button_text_font_size' => [
                'nullable', 'integer',
                'min:' . SiteVerifyEmail::BUTTON_TEXT_MIN_SIZE,
                'max:' . SiteVerifyEmail::BUTTON_TEXT_MAX_SIZE,
            ],
            'accent_color'      => ['required', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'active'            => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'button_text_font_size.min' => 'The button text size must be at least '
                . SiteVerifyEmail::BUTTON_TEXT_MIN_SIZE . 'px.',
            'button_text_font_size.max' => 'The button text size cannot exceed '
                . SiteVerifyEmail::BUTTON_TEXT_MAX_SIZE . 'px.',

            'accent_color.regex' => 'The accent color must be a valid hex color (e.g. #4f1d96).',
        ];
    }
}
