<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

/**
 * On edit the Auth Token is OPTIONAL: leave it blank to keep the stored token
 * (the admin never sees the raw value, so re-typing it on every edit is
 * impractical); provide a new value to rotate it. Mirrors
 * {@see UpdateMailgunKeyRequest} exactly.
 *
 * Everything else — the SID format checks, the E.164 normalisation of the sending
 * number and the "must have a sender" rule — is inherited, so the two forms
 * cannot drift apart.
 */
class UpdateTwilioConfigRequest extends StoreTwilioConfigRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['auth_token'] = ['nullable', 'string', 'min:16', 'max:500'];

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // Treat a blank/null token from the UI as "keep the existing token" — the
        // stored value must never be silently wiped by an edit. The SPA sends
        // JSON, where ConvertEmptyStringsToNull has already turned '' into null
        // and the payload lives in the JSON bag rather than $this->request, so it
        // has to be stripped from the actual input source.
        if ($this->input('auth_token') === null || $this->input('auth_token') === '') {
            $this->getInputSource()->remove('auth_token');
        }
    }
}
