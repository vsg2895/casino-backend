<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SpecialOffer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSpecialOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'casino_id'          => ['sometimes', 'required', 'integer', 'exists:casinos,id'],
            'bonus_category_id' => ['nullable', 'integer', 'exists:bonus_categories,id'],
            'title'              => ['sometimes', 'required', 'string', 'max:255'],
            /*
             * The public address, editable on purpose.
             *
             * A slug is generated once, from the title, and never regenerated —
             * renaming an offer must not silently move a URL that is already
             * indexed or linked (see SpecialOffer::booted()). The cost of that
             * rule was that a duplicate kept its "(Copy)" wording in the URL
             * forever: duplicate "2nd Deposit Bonus", rename the copy to "3rd
             * Deposit Bonus", and the link still read
             * `2nd_deposit_bonus_copy_mvlqbz`.
             *
             * So the address becomes something an editor can set, rather than
             * something only a developer could change. Sending it EMPTY is the
             * documented "regenerate from the current title" gesture — the
             * model's saving hook builds a fresh slug whenever the column is
             * blank.
             *
             * Normalised in prepareForValidation(), so an editor may type
             * "3rd Deposit Bonus" and get `3rd_deposit_bonus`. Unique across
             * every row INCLUDING soft-deleted ones, matching
             * SpecialOffer::generateUniqueSlug() — a trashed offer still owns
             * its address until it is purged, and may yet be restored.
             */
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
                // `special_offer` — the parameter name apiResource derives from
                // the `special-offers` URI, NOT the camelCase one the duplicate
                // route spells out. Getting it wrong returns null, ignore(null)
                // ignores nothing, and an offer then collides with its own
                // slug on every save that does not change it.
                Rule::unique('special_offers', 'slug')->ignore($this->route('special_offer')),
            ],
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

    /**
     * Let an editor type words and still get a valid address.
     *
     * Same transformation the generator applies to a title, so a hand-written
     * slug and a generated one have the same shape. A field left empty stays
     * empty — that is the "regenerate it for me" gesture, not an error.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('slug')) {
            return;
        }

        $slug = trim((string) $this->input('slug'));

        $this->merge([
            'slug' => $slug === '' ? null : SpecialOffer::slugBase($slug),
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.unique' => 'Another offer already uses that link. Pick a different one.',
            'slug.regex'  => 'A link may only contain lowercase letters, numbers and underscores.',
        ];
    }
}
