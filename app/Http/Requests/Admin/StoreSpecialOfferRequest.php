<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreSpecialOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'casino_id'          => ['required', 'integer', 'exists:casinos,id'],
            'title'              => ['required', 'string', 'max:255'],
            'image_path'         => ['nullable', 'string', 'max:500'],
            'banner_image'       => ['nullable', 'string', 'max:500'],
            'bonuses'            => ['nullable', 'string', 'max:255'],
            'affiliate_url'      => ['nullable', 'url', 'max:500'],
            'description'        => ['nullable', 'string'],
            'rating'             => ['nullable', 'integer', 'min:0', 'max:5'],
            'sort_order'         => ['nullable', 'integer', 'min:0'],
            'active'             => ['boolean'],
            // Per-record SEO overrides. Offers carry no meta_title/meta_description
            // columns, so a title here comes from the site's pattern; these two
            // are the only per-record SEO controls an offer has.
            // Structured bonus terms. All optional — an offer with none renders
            // exactly as it does today.
            'wagering_requirement' => ['nullable', 'string', 'max:120'],
            'min_deposit'          => ['nullable', 'string', 'max:120'],
            'max_cashout'          => ['nullable', 'string', 'max:120'],
            'bonus_code'           => ['nullable', 'string', 'max:60'],
            // A date already past would publish an offer that is expired the
            // moment it is saved, which is never what someone means.
            'expires_at'           => ['nullable', 'date', 'after_or_equal:today'],
            'terms_url'            => ['nullable', 'string', 'max:500', 'regex:#^https?://#'],
            'canonical_url' => ['nullable', 'string', 'max:500', 'regex:#^https?://#'],
            'noindex'       => ['sometimes', 'boolean'],
        ];
    }
}
