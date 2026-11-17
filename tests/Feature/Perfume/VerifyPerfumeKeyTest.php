<?php

declare(strict_types=1);

namespace Tests\Feature\Perfume;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifyPerfumeKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_key_refuses_every_request_with_503(): void
    {
        config(['perfume.api_key' => '']);

        $this->getJson('/api/perfume/public/settings', ['X-Perfume-Key' => ''])->assertStatus(503);
        $this->getJson('/api/perfume/public/settings', ['X-Perfume-Key' => 'anything'])->assertStatus(503);
    }

    public function test_missing_key_is_401_and_wrong_key_is_403(): void
    {
        config(['perfume.api_key' => 'secret-key']);

        $this->getJson('/api/perfume/public/settings')->assertStatus(401);
        $this->getJson('/api/perfume/public/settings', ['X-Perfume-Key' => 'wrong'])->assertStatus(403);
    }

    public function test_right_key_is_let_through(): void
    {
        config(['perfume.api_key' => 'secret-key']);

        $this->getJson('/api/perfume/public/settings', ['X-Perfume-Key' => 'secret-key'])
            ->assertOk()
            ->assertJsonPath('data.site_name', 'Big Glow Day');
    }

    public function test_admin_routes_need_a_sanctum_token(): void
    {
        $this->getJson('/api/perfume/admin/brands')->assertStatus(401);
    }
}
