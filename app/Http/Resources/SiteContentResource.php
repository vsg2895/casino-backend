<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\SiteContentEditor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Per-site content for the admin: either the whole overview (default content
 * plus every editable site) or one site's row after a save — `null` content
 * meaning the site now uses the default.
 */
class SiteContentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $resource = $this->resource;

        if (is_array($resource) && array_key_exists('sites', $resource)) {
            return $resource;
        }

        return [
            'site_id' => $resource['site_id'],
            'content' => SiteContentEditor::row($resource['row']),
        ];
    }
}
