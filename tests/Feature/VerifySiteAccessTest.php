<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VerifySiteAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('verify.site')
            ->get('/api/v1/public/sites/{site}/ping', function () {
                return response()->json(['site_id' => app('current_site')->id]);
            });
    }

    private function createSite(bool $active = true): array
    {
        $plain = Site::generateApiKey();

        $site = Site::create([
            'name'   => 'Test Site',
            'slug'   => 'test-site',
            'domain' => 'test.com',
            'api_key' => Hash::make($plain),
            'active' => $active,
        ]);

        return [$site, $plain];
    }

    public function test_returns_401_when_site_key_header_is_missing(): void
    {
        $this->getJson('/api/v1/public/sites/test-site/ping')
            ->assertStatus(401);
    }

    public function test_returns_404_when_site_slug_does_not_exist(): void
    {
        $this->getJson('/api/v1/public/sites/nonexistent/ping', [
            'X-Site-Key' => 'any-key',
        ])->assertStatus(404);
    }

    public function test_returns_404_when_site_is_inactive(): void
    {
        $this->createSite(active: false);

        $this->getJson('/api/v1/public/sites/test-site/ping', [
            'X-Site-Key' => 'any-key',
        ])->assertStatus(404);
    }

    public function test_returns_403_when_key_is_invalid(): void
    {
        $this->createSite();

        $this->getJson('/api/v1/public/sites/test-site/ping', [
            'X-Site-Key' => 'wrong-key',
        ])->assertStatus(403);
    }

    public function test_passes_with_valid_credentials_and_binds_site_to_container(): void
    {
        [$site, $plain] = $this->createSite();

        $this->getJson('/api/v1/public/sites/test-site/ping', [
            'X-Site-Key' => $plain,
        ])
            ->assertOk()
            ->assertJson(['site_id' => $site->id]);
    }
}
