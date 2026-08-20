<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SitePromotionEmail;
use App\Models\VerificationPromotionEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VerificationPromotionEmail
 *
 * Mirrors {@see SitePromotionEmailResource} (minus site_id, which this global
 * template has no concept of) and adds the settings.
 *
 * No credential VALUE is ever exposed here — only the id of the selected key and
 * its display name. The API keys themselves are `encrypted` casts hidden on
 * their own models and must never reach an admin response.
 */
class VerificationPromotionEmailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'from_name'         => $this->from_name,
            'from_email'        => $this->from_email,
            'subject'           => $this->subject,
            'preheader'         => $this->preheader,
            'hero_image_url'    => $this->hero_image_url,
            'hero_url'          => $this->hero_url,
            'top_button_text'   => $this->top_button_text,
            'heading'           => $this->heading,
            'intro_text'        => $this->intro_text,
            'secondary_text'    => $this->secondary_text,
            'cta_button_text'   => $this->cta_button_text,
            'disclaimer_text'   => $this->disclaimer_text,
            'unsubscribe_label' => $this->unsubscribe_label,

            ...collect(SitePromotionEmail::COLOR_DEFAULTS)
                ->map(fn (string $default, string $field): string => (string) ($this->{$field} ?: $default))
                ->all(),

            // ── Settings ─────────────────────────────────────────────────
            'active'            => $this->active,
            'delay_minutes'     => (int) $this->delay_minutes,
            'provider'          => $this->provider,
            'sendgrid_key_id'   => $this->sendgrid_key_id,
            'mailgun_key_id'    => $this->mailgun_key_id,
            'max_delay_minutes' => VerificationPromotionEmail::MAX_DELAY_MINUTES,

            // Whether SENDGRID_API_KEY is set on this server, so the admin can
            // hide the SendGrid option when there is nothing to send with —
            // the same rule the UI applies to a provider with no stored keys.
            // A boolean only: it reveals that a key exists, never its value.
            'sendgrid_env_available' => trim((string) config('mail.mailers.sendgrid.key', '')) !== '',

            'from_domain'       => (string) config('services.sendgrid.from_domain', 'example.com'),
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
