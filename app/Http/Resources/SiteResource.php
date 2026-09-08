<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'slug'             => $this->slug,
            'domain'           => $this->domain,
            'positioning'      => $this->positioning,
            'revalidation_url' => $this->revalidation_url,
            'settings'         => $this->settings,
            'active'           => $this->active,
<<<<<<< Updated upstream
            'newsletter_emails_enabled' => (bool) $this->newsletter_emails_enabled,
=======
            // Feature switches. Both default false — a new feature never
            // switches itself on for a live domain.
            'countries_enabled' => (bool) $this->countries_enabled,
            'reviews_enabled'   => (bool) $this->reviews_enabled,
            'operator_profile_enabled' => (bool) $this->operator_profile_enabled,
            'byline_enabled'    => (bool) $this->byline_enabled,
            'guides_enabled'    => (bool) $this->guides_enabled,
            'author_name'       => $this->author_name,
            'author_role'       => $this->author_role,
            'author_bio'        => $this->author_bio,
            'author_avatar_path' => $this->author_avatar_path,
            'methodology_page_slug' => $this->methodology_page_slug,
            // Cache health, for the sites list. Null until the first attempt.
            'last_revalidated_at'      => $this->last_revalidated_at,
            'last_revalidation_status' => $this->last_revalidation_status,
            'last_revalidation_error'  => $this->last_revalidation_error,
>>>>>>> Stashed changes
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
            // api_key is never included — $hidden on the model is the last line of defence,
            // but we keep it explicit here so a reviewer can audit the contract in one place.
        ];
    }
}
