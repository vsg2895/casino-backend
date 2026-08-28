<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SiteVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SiteVerifyEmail */
class SiteVerifyEmailResource extends JsonResource
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
            'header_title'      => $this->header_title,
            'header_subtitle'   => $this->header_subtitle,
            'heading'           => $this->heading,
            'intro_text'        => $this->intro_text,
            'offer_text'        => $this->offer_text,
            'spam_notice'       => $this->spam_notice,
            'footer_note'       => $this->footer_note,
            'postal_address'    => $this->postal_address,
            'contact_email'     => $this->contact_email,
            'hidden_blocks'     => array_values(array_keys(array_filter(
                $this->visibleBlocks(),
                static fn (bool $visible): bool => ! $visible,
            ))),
            'optional_blocks'   => SiteVerifyEmail::OPTIONAL_BLOCKS,
            'unsubscribe_label' => $this->unsubscribe_label,
            // Whether the footer link block is rendered. The label above is kept
            // either way, so the admin can restore the exact same link.
            'unsubscribe_enabled' => $this->showsUnsubscribeLink(),
            'copyright_text'    => $this->copyright_text,
            'verify_button_text'     => $this->verify_button_text,
            'verify_button_text_default' => SiteVerifyEmail::DEFAULT_BUTTON_TEXT,
            'button_text_font_size'  => $this->button_text_font_size,
            'button_text_min_size'   => SiteVerifyEmail::BUTTON_TEXT_MIN_SIZE,
            'button_text_max_size'   => SiteVerifyEmail::BUTTON_TEXT_MAX_SIZE,
            'button_text_default_size' => SiteVerifyEmail::BUTTON_TEXT_DEFAULT_SIZE,
            'accent_color'      => $this->accent_color,
            'footer_text_color' => $this->footer_text_color,
            'footer_text_color_default' => SiteVerifyEmail::DEFAULT_FOOTER_TEXT_COLOR,
            'active'            => $this->active,
            'from_domain'       => (string) config('services.sendgrid.from_domain', 'example.com'),
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
