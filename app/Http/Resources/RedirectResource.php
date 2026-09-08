<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Redirect;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Redirect
 *
 * `hits` is admin-only context and harmless publicly, but the public endpoint
 * ships a deliberately narrower shape — see PublicRedirectController.
 */
class RedirectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'site_id'          => $this->site_id,
            'source_path'      => $this->source_path,
            'destination_path' => $this->destination_path,
            'status_code'      => (int) $this->status_code,
            'active'           => (bool) $this->active,
            'hits'             => (int) $this->hits,
            'created_at'       => $this->created_at,
        ];
    }
}
