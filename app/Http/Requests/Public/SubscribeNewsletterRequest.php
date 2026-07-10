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
                // Already actively subscribed to THIS site → 422. Soft-deleted
                // (previously unsubscribed) rows are excluded so re-subscribing
                // is still allowed and restores the row.
                Rule::unique('newsletters', 'email')
                    ->where('site_id', $siteId)
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
