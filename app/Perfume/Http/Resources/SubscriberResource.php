<?php

declare(strict_types=1);

namespace App\Perfume\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Perfume\Models\Subscriber
 *
 * No `token`: it is the unsubscribe credential and never leaves the server.
 */
class SubscriberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'email'           => $this->email,
            'name'            => $this->name,
            'active'          => (bool) $this->active,
            'unsubscribed_at' => $this->unsubscribed_at?->toISOString(),
            'last_sent_at'    => $this->last_sent_at?->toISOString(),
            'created_at'      => $this->created_at?->toISOString(),
        ];
    }
}
