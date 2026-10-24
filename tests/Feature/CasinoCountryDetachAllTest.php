<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\Continent;
use App\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class CasinoCountryDetachAllTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private const string CONFIRM_MESSAGE = 'Send confirm=true — this removes every casino/country attachment.';

    private function attachedCasino(): Casino
    {
        $continent = Continent::create(['name' => 'Europe', 'slug' => 'europe', 'position' => 10]);
        $country = Country::create([
            'continent_id' => $continent->id,
            'name' => 'Malta',
            'slug' => 'malta',
            'code' => 'MT',
            'position' => 10,
            'active' => true,
        ]);
        $casino = Casino::factory()->create();
        $casino->countries()->attach($country->id);

        return $casino;
    }

    public function test_returns_422_with_the_custom_message_when_confirm_is_missing(): void
    {
        $this->actingAsAdmin();
        $this->attachedCasino();

        $this->postJson('/api/v1/admin/casino-countries/detach-all', [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => self::CONFIRM_MESSAGE,
                'errors' => ['confirm' => [self::CONFIRM_MESSAGE]],
            ]);

        $this->assertDatabaseCount('casino_country', 1);
    }

    public function test_returns_422_with_the_custom_message_when_confirm_is_false(): void
    {
        $this->actingAsAdmin();
        $this->attachedCasino();

        $this->postJson('/api/v1/admin/casino-countries/detach-all', ['confirm' => false])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => self::CONFIRM_MESSAGE,
                'errors' => ['confirm' => [self::CONFIRM_MESSAGE]],
            ]);

        $this->assertDatabaseCount('casino_country', 1);
    }

    public function test_removes_every_attachment_when_confirmed(): void
    {
        $this->actingAsAdmin();
        $this->attachedCasino();

        $this->postJson('/api/v1/admin/casino-countries/detach-all', ['confirm' => true])
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'removed' => 1,
                'message' => '1 attachment(s) removed. No casino is attached to any country now.',
            ]);

        $this->assertDatabaseCount('casino_country', 0);
    }
}
