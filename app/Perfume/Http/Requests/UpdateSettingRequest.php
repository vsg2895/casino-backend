<?php

declare(strict_types=1);

namespace App\Perfume\Http\Requests;

class UpdateSettingRequest extends PerfumeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $str = ['nullable', 'string', 'max:255'];
        $url = ['nullable', 'url', 'max:255'];
        $pct = ['nullable', 'integer', 'min:0', 'max:100'];

        return [
            'site_name'                => ['sometimes', 'required', 'string', 'max:255'],
            'domain'                   => ['sometimes', 'required', 'string', 'max:255'],
            'logo_path'                => $str,
            'accent_color'             => ['sometimes', 'required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'phone'                    => $str,
            'email'                    => ['nullable', 'email', 'max:255'],
            'hours'                    => $str,
            'whatsapp_url'             => $url,
            'viber_url'                => $url,
            'telegram_url'             => $url,
            'instagram_url'            => $url,
            'facebook_url'             => $url,
            'tiktok_url'               => $url,
            'youtube_url'              => $url,
            'topbar_sale_text'         => $str,
            'sale_percent'             => $pct,
            'delivery_local_time'      => $str,
            'delivery_free_from'       => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'delivery_national_time'   => $str,
            'bonus_percent'            => $pct,
            'samples_max_per_order'    => ['nullable', 'integer', 'min:1', 'max:50'],
            'payment_labels'           => ['nullable', 'array', 'max:12'],
            'payment_labels.*'         => ['string', 'max:40'],
            'map_embed_url'            => ['nullable', 'string', 'max:2000'],
            'hero_eyebrow'             => $str,
            'hero_title'               => $str,
            'hero_text'                => ['nullable', 'string', 'max:1000'],
            'hero_cta_primary_label'   => $str,
            'hero_cta_primary_url'     => $str,
            'hero_cta_secondary_label' => $str,
            'hero_cta_secondary_url'   => $str,
            'notes_title'              => $str,
            'new_arrivals_title'       => $str,
            'samples_title'            => $str,
            'samples_text'             => ['nullable', 'string', 'max:1000'],
            'samples_steps'            => ['nullable', 'array', 'max:6'],
            'samples_steps.*'          => ['string', 'max:255'],
            'samples_cta_label'        => $str,
            'samples_cta_url'          => $str,
            'brands_title'             => $str,
            'stores_title'             => $str,
            ...$this->seoRules(),
        ];
    }
}
