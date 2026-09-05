<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SmtpCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SmtpCredential
 *
 * NEVER exposes the raw `password` — only a length-only placeholder. The
 * password is write-only from the admin's perspective (set on create, replaced
 * on edit).
 *
 * Unlike {@see MailgunKeyResource}, which shows the first characters of the API
 * key, nothing of this value is revealed: an SMTP password is very often a
 * mailbox password reused elsewhere, so no prefix of it should reach a browser.
 * Host, port and username are returned — they are not secrets, and the admin
 * needs them to recognise a row.
 */
class SmtpCredentialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            // 'own_smtp' | 'mailgun_smtp'. The UI labels it "Type".
            'type'       => $this->type,
            'host'       => $this->host,
            'port'       => (int) $this->port,
            'username'   => $this->username,
            'encryption' => $this->encryption,
            // Read at send time for this channel — an own SMTP server has no
            // other source of sender identity.
            'from_address' => $this->from_address,
            'from_name'    => $this->from_name,
            'masked_password' => $this->maskedPassword(),
            'status'     => $this->status,
            'can_authenticate' => $this->canAuthenticate(),
            'last_run_at' => $this->last_run_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
