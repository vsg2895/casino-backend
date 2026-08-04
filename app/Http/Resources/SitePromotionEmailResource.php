<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SitePromotionEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SitePromotionEmail */
class SitePromotionEmailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'site_id'           => $this->site_id,
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
            // Palette. Each falls back to the design default so a row written
            // before these columns existed still returns a usable colour.
            ...collect(SitePromotionEmail::COLOR_DEFAULTS)
                ->map(fn (string $default, string $field): string => (string) ($this->{$field} ?: $default))
                ->all(),
            'active'            => $this->active,
            // Helps the admin UI show which domain the from address must use.
            'from_domain'       => (string) config('services.sendgrid.from_domain', 'example.com'),
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
