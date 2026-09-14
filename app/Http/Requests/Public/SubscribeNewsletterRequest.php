<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Support\Validation\ValidationMessage;
use Illuminate\Contracts\Validation\Validator;
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

            /*
             * HONEYPOT. Rendered hidden on every subscribe form; a human never
             * fills it, a naive bot fills every field it finds.
             *
             * Cheap and first: this runs before the site is touched and long
             * before a validation credit could be spent. Named `website` rather
             * than something obviously trap-like, because the name is visible in
             * the markup.
             *
             * The message is deliberately the same generic one a failed verdict
             * produces, so probing cannot distinguish the two.
             */
            'website' => ['prohibited'],
        ];
    }

    /**
     * Never name the honeypot in the response.
     *
     * A 422 carrying `errors.website` tells a bot precisely which field to leave
     * alone next time, which is the one thing a honeypot must not do. The error
     * is re-keyed to `email` so a tripped trap is indistinguishable from a
     * rejected address — same key, same generic message, same status.
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();

        if ($errors->has('website')) {
            $message = $errors->first('website');
            $errors->forget('website');
            $errors->add('email', $message);
        }

        parent::failedValidation($validator);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique'      => 'You are already subscribed.',
            // The honeypot answers with the GENERIC line on purpose, and now that
            // real rejections say something specific that matters more, not less:
            // a bot that trips it must not be able to tell "you are a bot" apart
            // from an ordinary rejection. Referencing the constant rather than
            // repeating the sentence keeps the two from drifting apart.
            'website.prohibited' => ValidationMessage::FALLBACK,
        ];
    }
}
