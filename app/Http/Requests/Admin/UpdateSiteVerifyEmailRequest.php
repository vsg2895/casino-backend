<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SiteVerifyEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSiteVerifyEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already behind auth:sanctum.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from_name'         => ['required', 'string', 'max:120'],
            'from_email'        => ['required', 'string', 'email', 'max:180'],
            'subject'           => ['required', 'string', 'max:200'],
            'header_title'      => ['nullable', 'string', 'max:150'],
            'header_subtitle'   => ['nullable', 'string', 'max:250'],
            'heading'           => ['nullable', 'string', 'max:150'],
            'intro_text'        => ['nullable', 'string', 'max:1000'],
            'offer_text'        => ['nullable', 'string', 'max:1000'],
            'spam_notice'       => ['nullable', 'string', 'max:1000'],
            'footer_note'       => ['nullable', 'string', 'max:1000'],
            // Footer identity — a physical address and a monitored reply mailbox.
            'postal_address'    => ['nullable', 'string', 'max:300'],
            'contact_email'     => ['nullable', 'string', 'max:180'],

            // Which optional blocks are switched OFF. Visibility only — each keeps
            // its own text, which is what makes removal reversible.
            'hidden_blocks'   => ['sometimes', 'array'],
            'hidden_blocks.*' => ['string', Rule::in(SiteVerifyEmail::OPTIONAL_BLOCKS)],

            'unsubscribe_label' => ['required', 'string', 'max:80'],
            // `sometimes`, not `required`: an existing caller that does not send
            // the key leaves the stored value alone and keeps its current output.
            // The label above stays required either way, so removing the link
            // never discards the wording needed to restore it.
            'unsubscribe_enabled' => ['sometimes', 'boolean'],
            'copyright_text'    => ['nullable', 'string', 'max:200'],
            'accent_color'      => ['required', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'active'            => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'accent_color.regex' => 'The accent color must be a valid hex color (e.g. #4f1d96).',
        ];
    }
}
