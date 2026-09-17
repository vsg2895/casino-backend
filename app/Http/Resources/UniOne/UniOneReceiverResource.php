<?php

declare(strict_types=1);

namespace App\Http\Resources\UniOne;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\UniOne\UniOneReceiver */
class UniOneReceiverResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'              => (int) $this->id,
            'email'           => $this->email,
            'name'            => $this->name,
            'status'          => $this->status,
            'consent_source'  => $this->consent_source,
            'consent_at'      => $this->consent_at?->toISOString(),
            'last_sent_at'    => $this->last_sent_at?->toISOString(),
            'last_status'     => $this->last_status,
            'retry_after'     => $this->retry_after?->toISOString(),
            'send_count'      => (int) $this->send_count,
            'bounce_count'    => (int) $this->bounce_count,
            'complaint_count' => (int) $this->complaint_count,
            // What the send path would decide, surfaced so the list explains
            // itself rather than making an operator infer it from four columns.
            'is_sendable'     => $this->status === 'active'
                && $this->hasConsent()
                && ($this->retry_after === null || $this->retry_after->isPast()),
            'notes'           => $this->notes,
            'created_at'      => $this->created_at?->toISOString(),
        ];
    }
}
