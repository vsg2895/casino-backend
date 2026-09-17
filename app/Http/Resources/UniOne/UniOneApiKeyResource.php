<?php

declare(strict_types=1);

namespace App\Http\Resources\UniOne;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A UniOne key as the admin sees it.
 *
 * `api_key` and `webhook_secret` are absent by construction — the only
 * representation of the key that leaves this process is `masked_key`, which is
 * the last four characters. The brief asks for Replace, never Reveal, so there
 * is no endpoint anywhere that returns the plaintext.
 *
 * `webhook_url` DOES include the token, and deliberately: an operator has to
 * paste that URL into UniOne, so it must be visible. It is a path token, not a
 * signing secret — the cryptographic check is the MD5-with-api-key hash.
 *
 * @mixin \App\Models\UniOne\UniOneApiKey
 */
class UniOneApiKeyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => (int) $this->id,
            'name'       => $this->name,
            'masked_key' => $this->maskedKey(),
            'key_type'   => $this->key_type,
            'project_id' => $this->project_id,
            'region'     => $this->region,
            'base_url'   => $this->base_url,
            'is_active'  => (bool) $this->is_active,
            'is_default' => (bool) $this->is_default,
            'default_from_email' => $this->default_from_email,
            'default_from_name'  => $this->default_from_name,
            'track_links'        => (bool) $this->track_links,
            'track_read'         => (bool) $this->track_read,
            'timeout_seconds'    => (int) $this->timeout_seconds,
            'last_verified_at'   => $this->last_verified_at?->toISOString(),
            'last_verify_status' => $this->last_verify_status,
            'is_verified'        => $this->isVerified(),
            'notes'              => $this->notes,
            'webhook_url'        => url("/api/v1/unione/webhook/{$this->webhook_secret}"),
            'created_at'         => $this->created_at?->toISOString(),
            'updated_at'         => $this->updated_at?->toISOString(),
        ];
    }
}
