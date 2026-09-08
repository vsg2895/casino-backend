<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MailgunReceiver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MailgunReceiver
 *
 * `unsubscribe_token` is deliberately absent. It is a bearer credential — anyone
 * holding it can unsubscribe that person — so it belongs in the emailed link and
 * nowhere else, exactly as the newsletter tokens are treated.
 */
class MailgunReceiverResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'email'               => $this->email,
            'name'                => $this->name,
            'source'              => $this->source,
            'consent_recorded_at' => $this->consent_recorded_at,
            'is_active'           => (bool) $this->is_active,
            'unsubscribed_at'     => $this->unsubscribed_at,
            'last_sent_at'        => $this->last_sent_at,
            'sent_count'          => (int) $this->sent_count,
            'last_error'          => $this->last_error,
            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,
        ];
    }
}
