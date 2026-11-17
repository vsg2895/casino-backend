<?php

declare(strict_types=1);

namespace App\Perfume\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Perfume\Models\HomeCard */
class HomeCardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'kind'       => $this->kind,
            'number'     => $this->number,
            'title'      => $this->title,
            'text'       => $this->text,
            'link_label' => $this->link_label,
            'url'        => $this->url,
            'image_path' => $this->image_path,
            'image_url'  => ImageUrl::of($this->image_path),
            'active'     => (bool) $this->active,
            'position'   => (int) $this->position,
        ];
    }
}
