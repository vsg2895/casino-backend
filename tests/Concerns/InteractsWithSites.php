<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

trait InteractsWithSites
{
    /**
     * Create a site and return [Site, plainApiKey] so tests can authenticate
     * against the site-keyed public endpoints.
     *
     * @param array<string, mixed> $attrs
     * @return array{0: Site, 1: string}
     */
    protected function siteWithKey(array $attrs = []): array
    {
        $plain = Site::generateApiKey();
        $site = Site::factory()->create([...$attrs, 'api_key' => Hash::make($plain)]);

        return [$site, $plain];
    }

    /** @return array<string, string> */
    protected function siteHeaders(string $key): array
    {
        return ['X-Site-Key' => $key, 'Accept' => 'application/json'];
    }

    protected function publicBase(Site $site): string
    {
        return "/api/v1/public/sites/{$site->slug}";
    }

    /** Authenticate the test as an admin user (admin endpoints sit behind auth:sanctum). */
    protected function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        return $user;
    }
}
