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
        $fromDomain = (string) config('services.sendgrid.from_domain', 'example.com');

        return [
            'from_name'         => ['required', 'string', 'max:120'],
            // Sender must live on the SendGrid-verified domain, e.g. offers@{domain}.
            'from_email'        => [
                'required', 'string', 'email', 'max:180',
                'ends_with:@' . $fromDomain,
            ],
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
        $fromDomain = (string) config('services.sendgrid.from_domain', 'example.com');

        return [
            'from_email.ends_with' => "The from address must use the SendGrid-verified domain (@{$fromDomain}).",
            'accent_color.regex'   => 'The accent color must be a valid hex color (e.g. #4f1d96).',
        ];
    }
}
