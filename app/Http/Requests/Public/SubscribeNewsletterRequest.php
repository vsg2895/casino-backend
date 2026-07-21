<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscribeNewsletterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Resolved by the verify.site middleware. Guarded so the request can
        // still be constructed outside that context without throwing.
        $siteId = app()->bound('current_site') ? app('current_site')->id : null;

        return [
            'email' => [
                'required', 'email', 'max:255',
                // Block ONLY when this email is already VERIFIED on this site → 422
                // "You are already subscribed." A pending (unverified) row does NOT
                // block: re-submitting re-sends the verification email. Soft-deleted
                // (previously unsubscribed) rows are excluded so re-subscribing is
                // allowed and restores the row.
                Rule::unique('newsletters', 'email')
                    ->where('site_id', $siteId)
                    ->where('verified', true)
                    ->whereNull('deleted_at'),
            ],
            // Optional display name captured by some subscribe forms (e.g. modal).
            'full_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'You are already subscribed.',
        ];
    }
}
