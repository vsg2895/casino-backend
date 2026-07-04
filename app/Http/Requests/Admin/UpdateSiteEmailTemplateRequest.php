<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSiteEmailTemplateRequest extends FormRequest
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
            // Any valid address. Deliverability (domain alignment with your mail
            // provider) is an operational concern, not a hard validation rule —
            // tying it to a config domain made previews/saves break whenever the
            // env drifted, which is why it was removed.
            'from_email'        => ['required', 'string', 'email', 'max:180'],
            'subject'           => ['required', 'string', 'max:200'],
            'header_title'      => ['required', 'string', 'max:150'],
            'header_subtitle'   => ['required', 'string', 'max:250'],
            'heading'           => ['required', 'string', 'max:150'],
            'intro_text'        => ['required', 'string', 'max:1000'],
            'offer_text'        => ['required', 'string', 'max:1000'],
            'spam_notice'       => ['required', 'string', 'max:1000'],
            'footer_note'       => ['required', 'string', 'max:1000'],
            'unsubscribe_label' => ['required', 'string', 'max:80'],
            'copyright_text'    => ['required', 'string', 'max:200'],
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
