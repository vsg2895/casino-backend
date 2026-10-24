<?php

declare(strict_types=1);

namespace App\Http\Requests\UniOne;

/**
 * Editing a UniOne API key. The secret is optional here: blank means "leave it
 * alone", which lets the edit form open without ever holding the secret.
 */
class UpdateUniOneKeyRequest extends StoreUniOneKeyRequest
{
    /** @return list<string> */
    protected function apiKeyRule(): array
    {
        return ['nullable', 'string', 'max:500'];
    }
}
