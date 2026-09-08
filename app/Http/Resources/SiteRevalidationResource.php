<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SiteRevalidation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SiteRevalidation */
class SiteRevalidationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'tags'        => $this->tags ?? [],
            'status'      => $this->status,
            'http_status' => $this->http_status,
            'error'       => $this->error,
            'duration_ms' => (int) $this->duration_ms,
            'triggered_by' => $this->triggered_by,
            'created_at'  => $this->created_at?->toISOString(),
        ];
    }
}
