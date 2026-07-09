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
            'unsubscribe_label' => $this->unsubscribe_label,
            'copyright_text'    => $this->copyright_text,
            'accent_color'      => $this->accent_color,
            'active'            => $this->active,
            'from_domain'       => (string) config('services.sendgrid.from_domain', 'example.com'),
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
