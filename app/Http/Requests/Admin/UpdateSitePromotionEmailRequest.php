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
            // ── Structural: an email cannot exist without these ──────────
            'from_name'         => ['required', 'string', 'max:120'],
            // Any valid address (see UpdateSiteEmailTemplateRequest) — deliverability
            // is operational, not tied to a config domain that can drift.
            'from_email'        => ['required', 'string', 'email', 'max:180'],
            'subject'           => ['required', 'string', 'max:200'],
            // The opt-out link is a legal requirement on marketing mail, so its
            // label is the one piece of body copy that cannot be removed.
            'unsubscribe_label' => ['required', 'string', 'max:80'],

            // ── Content blocks: every one is independently removable ─────
            // Clearing a field drops that block from the email entirely (see
            // the mail.promotion.offer view). No field requires another: an
            // admin can delete the image, the link, either button or any
            // paragraph in any combination.
            'preheader'         => ['nullable', 'string', 'max:250'],
            'hero_image_url'    => ['nullable', 'url', 'max:500'],
            'hero_url'          => ['nullable', 'string', 'max:500'],
            'top_button_text'   => ['nullable', 'string', 'max:80'],
            'cta_button_text'   => ['nullable', 'string', 'max:80'],
            'heading'           => ['nullable', 'string', 'max:150'],
            'intro_text'        => ['nullable', 'string', 'max:1000'],
            'secondary_text'    => ['nullable', 'string', 'max:1000'],
            'disclaimer_text'   => ['nullable', 'string', 'max:1000'],
            'button_color'      => ['required', 'string', $hex],
            'accent_color'      => ['required', 'string', $hex],
            'active'            => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'button_color.regex' => 'The button color must be a valid hex color (e.g. #75B636).',
            'accent_color.regex' => 'The accent color must be a valid hex color (e.g. #f3a333).',
        ];
    }
}
