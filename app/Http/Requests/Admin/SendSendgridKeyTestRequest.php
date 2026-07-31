<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Mail\EmailTemplateCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an admin SendGrid key test: which template, rendered for which
 * site, delivered to which address. All three are required — the key itself
 * comes from the route binding.
 */
class SendSendgridKeyTestRequest extends FormRequest
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
            'to'       => ['required', 'string', 'email', 'max:180'],
            'site_id'  => ['required', 'integer', 'exists:sites,id'],
            // Whitelist comes from the catalog, so a future template is
            // accepted here the moment it is registered.
            'template' => ['required', 'string', Rule::in(app(EmailTemplateCatalog::class)->keys())],
            // Optional — drives the "Dear {name}," greeting, as in the per-site tests.
            'name'     => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to.required'       => 'Enter the address to send the test to.',
            'site_id.required'  => 'Select which website the template should be rendered for.',
            'site_id.exists'    => 'The selected website no longer exists.',
            'template.required' => 'Select which email template to send.',
            'template.in'       => 'The selected email template is not available.',
        ];
    }
}
