<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SendTestSiteEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to'   => ['required', 'string', 'email', 'max:180'],
            // Optional — drives the "Dear {name}," greeting in the test email.
            'name' => ['nullable', 'string', 'max:255'],
            // Optional — for the global Promotion After Verification test, which
            // registered site the {{site_name}} / {{site_url}} placeholders
            // resolve against. Ignored by the per-site template tests, which
            // already know their site from the route.
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ];
    }
}
