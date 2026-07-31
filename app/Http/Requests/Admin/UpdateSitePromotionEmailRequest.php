<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSitePromotionEmailRequest extends FormRequest
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
            'from_name'         => ['required', 'string', 'max:120'],
            // Any valid address (see UpdateSiteEmailTemplateRequest) — deliverability
            // is operational, not tied to a config domain that can drift.
            'from_email'        => ['required', 'string', 'email', 'max:180'],
            'subject'           => ['required', 'string', 'max:200'],
            'preheader'         => ['required', 'string', 'max:250'],
            // Removable elements — cleared means "drop it from the email"
            // rather than "render it empty". See the mail.promotion.offer view.
            'hero_image_url'    => ['nullable', 'url', 'max:500'],
            'top_button_text'   => ['nullable', 'string', 'max:80'],
            'cta_button_text'   => ['nullable', 'string', 'max:80'],
            // The offer/affiliate link every CTA points at. Allows
            // {{placeholders}} so it can target {{site_url}}. Only required
            // while at least one element that links to it is still present.
            'hero_url'          => [
                'nullable',
                'required_with:hero_image_url,top_button_text,cta_button_text',
                'string',
                'max:500',
            ],
            'heading'           => ['required', 'string', 'max:150'],
            'intro_text'        => ['required', 'string', 'max:1000'],
            'secondary_text'    => ['required', 'string', 'max:1000'],
            'disclaimer_text'   => ['required', 'string', 'max:1000'],
            'unsubscribe_label' => ['required', 'string', 'max:80'],
            'button_color'      => ['required', 'string', $hex],
            'accent_color'      => ['required', 'string', $hex],
            'active'            => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'button_color.regex'   => 'The button color must be a valid hex color (e.g. #75B636).',
            'accent_color.regex'   => 'The accent color must be a valid hex color (e.g. #f3a333).',
            'hero_url.required_with' => 'Add the offer link, or remove the hero image and both buttons.',
        ];
    }
}
