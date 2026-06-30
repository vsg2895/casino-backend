<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $password = 'secret123'): User
    {
        return User::factory()->create(['password' => bcrypt($password)]);
    }

    // ── POST /api/v1/admin/auth/login ──────────────────────────────────────────

    public function test_login_returns_token_on_valid_credentials(): void
    {
        $user = $this->makeUser('secret123');

        $this->postJson('/api/v1/admin/auth/login', [
            'email'    => $user->email,
            'password' => 'secret123',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'roles']]);
    }

    public function test_login_returns_401_on_wrong_password(): void
    {
        $user = $this->makeUser('secret123');

        $this->postJson('/api/v1/admin/auth/login', [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_login_returns_422_when_fields_missing(): void
    {
        $this->postJson('/api/v1/admin/auth/login', [])->assertStatus(422);
    }

    // ── GET /api/v1/admin/auth/me ──────────────────────────────────────────────

    public function test_me_returns_user_when_authenticated(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/auth/me')
            ->assertOk()
            ->assertJson(['id' => $user->id, 'email' => $user->email]);
    }

    public function test_me_returns_401_when_unauthenticated(): void
    {
        $this->getJson('/api/v1/admin/auth/me')->assertStatus(401);
    }

    // ── POST /api/v1/admin/auth/logout ─────────────────────────────────────────

    public function test_logout_revokes_token(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/admin/auth/logout')
            ->assertOk();

        // Within a single test, Sanctum's guard caches the resolved user in memory,
        // so a second HTTP call would still pass. Verify revocation via DB instead.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_returns_401_when_unauthenticated(): void
    {
        $this->postJson('/api/v1/admin/auth/logout')->assertStatus(401);
    }
}
