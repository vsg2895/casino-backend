<?php

declare(strict_types=1);

namespace App\Perfume\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Perfume\Models\Setting */
class SettingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->resource->attributesToArray();
        unset($data['id'], $data['created_at'], $data['updated_at']);

        $data['logo_url'] = ImageUrl::of($this->logo_path);
        $data['payment_labels'] = $this->payment_labels ?? [];
        $data['samples_steps'] = $this->samples_steps ?? [];
        $data['updated_at'] = $this->updated_at?->toISOString();

        return $data;
    }
}
