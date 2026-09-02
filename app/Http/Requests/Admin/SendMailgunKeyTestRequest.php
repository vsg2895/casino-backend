<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Controllers\Api\Admin\MailgunKeyController;
use App\Services\Mail\EmailTemplateCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an admin Mailgun key test: which template, rendered for which
 * site, delivered to which address. All three are required — the key itself
 * comes from the route binding.
 */
class SendMailgunKeyTestRequest extends FormRequest
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
            // Optional for the MAILGUN test only: that dialog has no website
            // picker, so the controller falls back to the first active site.
            // SendGrid's own test request still requires it — see
            // SendSendgridKeyTestRequest, which is deliberately not changed.
            'site_id'  => ['nullable', 'integer', 'exists:sites,id'],
            // Catalog keys, PLUS this screen's own connection test. The test is
            // deliberately not in the catalog — that is shared with the SendGrid
            // dialog and the warmup picker, and this option belongs to neither.
            'template' => [
                'required',
                'string',
                Rule::in([
                    MailgunKeyController::TEMPLATE_CONNECTION_TEST,
                    ...app(EmailTemplateCatalog::class)->keys(),
                ]),
            ],
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
