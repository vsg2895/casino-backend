<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WarmupEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WarmupEmail */
class WarmupEmailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->id,
            'email' => $this->email,

            // When this address was last SUCCESSFULLY contacted; null = never.
            // Exposed because it is what the cooldown filter reads — without it
            // the admin cannot tell why an address was skipped by a run.
            'last_sent_at' => $this->last_sent_at,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
