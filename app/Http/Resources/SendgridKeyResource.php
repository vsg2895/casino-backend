<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SendgridKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SendgridKey
 *
 * NEVER exposes the raw `api_key` — only a masked preview. The plaintext key is
 * write-only from the admin's perspective (set on create, replaced on edit).
 */
class SendgridKeyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'masked_key' => $this->maskedKey(),
            'status'     => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
