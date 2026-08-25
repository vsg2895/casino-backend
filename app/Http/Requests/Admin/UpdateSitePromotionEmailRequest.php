<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SitePromotionEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Where the buttons point. A plain string, not `url`, for the same
            // reason hero_url is: affiliate destinations carry tracking macros and
            // {{site_url}} placeholders the `url` rule rejects. Empty falls back
            // to hero_url, so existing rows keep their current target.
            'cta_button_url'    => ['nullable', 'string', 'max:500'],

            // Which optional blocks are switched OFF. Visibility only — every
            // block's own content is stored separately and is never touched by
            // hiding it, which is what makes removal reversible. `sometimes`, so a
            // caller omitting the key leaves the stored selection alone.
            'hidden_blocks'   => ['sometimes', 'array'],
            'hidden_blocks.*' => ['string', Rule::in(SitePromotionEmail::OPTIONAL_BLOCKS)],
            'heading'           => ['nullable', 'string', 'max:150'],
            'intro_text'        => ['nullable', 'string', 'max:1000'],
            'secondary_text'    => ['nullable', 'string', 'max:1000'],
            'disclaimer_text'   => ['nullable', 'string', 'max:1000'],
            'button_color'      => ['required', 'string', $hex],
            'accent_color'      => ['required', 'string', $hex],

            // ── Palette: canvas + per-section text ───────────────────────
            // Nullable rather than required so a client that predates these
            // fields keeps working; the model falls back to the design default
            // for anything omitted. Not removable content — a cleared colour is
            // a missing colour, not a dropped block.
            'background_color'     => ['nullable', 'string', $hex],
            'heading_color'        => ['nullable', 'string', $hex],
            'text_color'           => ['nullable', 'string', $hex],
            'secondary_text_color' => ['nullable', 'string', $hex],
            'muted_text_color'     => ['nullable', 'string', $hex],

            'active'            => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'button_color.regex'         => 'The button color must be a valid hex color (e.g. #75B636).',
            'accent_color.regex'         => 'The accent color must be a valid hex color (e.g. #f3a333).',
            'background_color.regex'     => 'The background color must be a valid hex color (e.g. #000000).',
            'heading_color.regex'        => 'The heading color must be a valid hex color (e.g. #ffffff).',
            'text_color.regex'           => 'The text color must be a valid hex color (e.g. #ffffff).',
            'secondary_text_color.regex' => 'The secondary text color must be a valid hex color (e.g. #d9d9d9).',
            'muted_text_color.regex'     => 'The muted text color must be a valid hex color (e.g. #b3b3b3).',
        ];
    }
}
