<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Returned ONLY at site creation and key rotation.
 * The plain api_key is shown exactly once and never again.
 */
class SiteRegistrationResource extends JsonResource
{
    public function __construct(Site $resource, private readonly string $plainApiKey)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                               => $this->id,
            'name'                             => $this->name,
            'slug'                             => $this->slug,
            'domain'                           => $this->domain,
            'revalidation_url'                 => $this->revalidation_url,
            'settings'                         => $this->settings,
            'active'                           => $this->active,
            'created_at'                       => $this->created_at,
            'updated_at'                       => $this->updated_at,
            'api_key'                          => $this->plainApiKey,
            'this_key_will_not_be_shown_again' => true,
        ];
    }
}
