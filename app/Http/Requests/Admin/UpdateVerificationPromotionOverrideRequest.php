<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One site's image + link overrides for the post-verification promotion.
 *
 * Every field is nullable, and every field is a URL or nothing. There is no
 * text field here and there must never be one: the promotion's copy is global
 * and stays global, so an override that could carry a heading would be a way
 * to change the email's words for one site — the exact thing this feature was
 * asked NOT to do.
 */
class UpdateVerificationPromotionOverrideRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Same rule as the template's own field, so an override cannot
            // hold something the default could not.
            'hero_image_url'        => ['nullable', 'url', 'max:500'],
            'hero_url'              => ['nullable', 'url', 'max:500'],
            'top_button_url'        => ['nullable', 'url', 'max:500'],
            'cta_button_url'        => ['nullable', 'url', 'max:500'],
            'email_preferences_url' => ['nullable', 'url', 'max:500'],

            // Positional targets for the template's own footer links. URLs
            // only — no label, so this cannot rename anything. A blank entry
            // means "leave that link on its default".
            'footer_link_urls'   => ['nullable', 'array', 'max:12'],
            'footer_link_urls.*' => ['nullable', 'url', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'footer_link_urls.*.url' => 'Each footer link needs a full URL, including https://.',
        ];
    }
}
