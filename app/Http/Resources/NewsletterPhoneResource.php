<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NewsletterBasedOnPhone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NewsletterBasedOnPhone */
class NewsletterPhoneResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'phone'        => $this->phone,
            'opted_out'    => (bool) $this->opted_out,
            'opted_out_at' => $this->opted_out_at,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
