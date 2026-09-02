<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MailgunKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MailgunKey
 *
 * NEVER exposes the raw `api_key` — only a masked preview. The plaintext key is
 * write-only from the admin's perspective (set on create, replaced on edit).
 * The domain IS returned: it is not a secret and the admin needs to see which
 * sending domain a credential belongs to.
 */
class MailgunKeyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'domain'     => $this->domain,
            'region'     => $this->region,
            // Sender identity, recorded for reference only — no send path reads
            // it. Both may legitimately be null.
            'from_address' => $this->from_address,
            'from_name'    => $this->from_name,
            'masked_key' => $this->maskedKey(),
            'status'     => $this->status,
            // Whether this credential can AUTHENTICATE. Deliberately not tied
            // to sender identity — a credential with no from_address is
            // complete, because the site template supplies one.
            'can_authenticate' => $this->canAuthenticate(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
