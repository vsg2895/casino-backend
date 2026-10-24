<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
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

    private function createSite(bool $active = true, string $slug = 'test-site'): array
    {
        $plain = Site::generateApiKey();

        $site = Site::create([
            'name' => 'Test Site',
            'slug' => $slug,
            'domain' => $slug.'.test',
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

    // ── Verification caching ──────────────────────────────────────────────
    //
    // A successful bcrypt check is remembered so repeat requests skip it. These
    // pin the security contract that makes that safe: nothing but a CORRECT key
    // for THIS site's CURRENT hash is ever remembered, and the 404 checks still
    // run on every request.

    private function ping(string $slug, string $key): TestResponse
    {
        return $this->getJson("/api/v1/public/sites/{$slug}/ping", ['X-Site-Key' => $key]);
    }

    /**
     * Swap in a hasher that counts check() calls and delegates everything to
     * the real one, so a test can assert how often bcrypt actually ran.
     */
    private function countBcryptChecks(): object
    {
        $counter = new class(app('hash')) implements Hasher
        {
            public int $checks = 0;

            public function __construct(private readonly Hasher $inner) {}

            public function info($hashedValue): array
            {
                return $this->inner->info($hashedValue);
            }

            public function make($value, array $options = []): string
            {
                return $this->inner->make($value, $options);
            }

            public function check($value, $hashedValue, array $options = []): bool
            {
                $this->checks++;

                return $this->inner->check($value, $hashedValue, $options);
            }

            public function needsRehash($hashedValue, array $options = []): bool
            {
                return $this->inner->needsRehash($hashedValue, $options);
            }
        };

        Hash::swap($counter);

        return $counter;
    }

    public function test_a_repeat_request_with_the_same_valid_key_runs_bcrypt_once(): void
    {
        [, $plain] = $this->createSite();
        $hasher = $this->countBcryptChecks();

        $this->ping('test-site', $plain)->assertOk();
        $this->ping('test-site', $plain)->assertOk();

        $this->assertSame(1, $hasher->checks);
    }

    public function test_a_wrong_key_is_checked_with_bcrypt_every_time(): void
    {
        $this->createSite();
        $hasher = $this->countBcryptChecks();

        $this->ping('test-site', 'wrong-key')->assertForbidden();
        $this->ping('test-site', 'wrong-key')->assertForbidden();

        $this->assertSame(2, $hasher->checks);
    }

    public function test_a_rotated_key_is_rejected_on_the_very_next_request(): void
    {
        [$site, $old] = $this->createSite();
        $this->ping('test-site', $old)->assertOk();

        $new = $site->rotateApiKey();

        $this->ping('test-site', $old)->assertForbidden();
        $this->ping('test-site', $new)->assertOk();
    }

    public function test_a_site_deactivated_after_a_verified_request_returns_404(): void
    {
        [$site, $plain] = $this->createSite();
        $this->ping('test-site', $plain)->assertOk();

        $site->update(['active' => false]);

        $this->ping('test-site', $plain)->assertNotFound();
    }

    public function test_a_key_verified_for_one_site_is_rejected_by_another(): void
    {
        [, $keyA] = $this->createSite(slug: 'site-a');
        $this->createSite(slug: 'site-b');
        $this->ping('site-a', $keyA)->assertOk();

        $this->ping('site-b', $keyA)->assertForbidden();
    }

    public function test_a_cache_outage_falls_back_to_the_bcrypt_check(): void
    {
        [, $plain] = $this->createSite();
        Cache::shouldReceive('get')->andThrow(new RuntimeException('cache down'));
        Cache::shouldReceive('put')->andThrow(new RuntimeException('cache down'));

        $this->ping('test-site', $plain)->assertOk();
        $this->ping('test-site', 'wrong-key')->assertForbidden();
    }
}
