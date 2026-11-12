<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The casino/offer × site content matrix, already shaped by the controller:
 * `{ type, sites: [{id, name, slug}], rows: [{id, name, slug, casino_name, cells: {site_slug: status}}] }`.
 */
class ContentCoverageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
