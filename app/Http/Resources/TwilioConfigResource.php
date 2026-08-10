<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TwilioConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TwilioConfig
 *
 * The Auth Token is NEVER exposed — only a masked preview, the same rule the
 * SendGrid and Mailgun key resources follow. The raw value leaves the database
 * exactly once, inside {@see \App\Services\Sms\TwilioSmsClient}, to sign a
 * request.
 */
class TwilioConfigResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            // Masked: account-identifying, though not a credential on its own.
            'account_sid' => $this->maskedAccountSid(),
            // A preview only — never enough of the token to be usable.
            'auth_token'  => $this->maskedToken(),

            'from_number'           => $this->from_number,
            'messaging_service_sid' => $this->messaging_service_sid,
            // Which of the two the sends will actually use, resolved by the same
            // method the client uses, so the UI cannot disagree with reality.
            'sender'                => $this->senderLabel(),
            'has_sender'            => $this->hasSender(),

            'status'     => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
