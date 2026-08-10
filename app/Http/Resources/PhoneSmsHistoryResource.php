<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PhoneSmsHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PhoneSmsHistory */
class PhoneSmsHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'phone'       => $this->phone,
            'status'      => $this->status,
            'message_sid' => $this->message_sid,
            'error_code'  => $this->error_code,
            'error'       => $this->error,
            'body'        => $this->body,

            // Only loaded when the caller eager-loads it; the credential may also
            // have been deleted since, which nulls the FK by design.
            'twilio_config' => $this->whenLoaded(
                'twilioConfig',
                fn (): ?array => $this->twilioConfig === null ? null : [
                    'id'   => $this->twilioConfig->id,
                    'name' => $this->twilioConfig->name,
                ],
            ),

            'created_at' => $this->created_at,
        ];
    }
}
