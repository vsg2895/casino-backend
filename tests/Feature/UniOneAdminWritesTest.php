<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UniOne\UniOneApiKey;
use App\Models\UniOne\UniOneReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Request contracts of the UniOne admin writes: keys, receivers and sends.
 *
 * UniOneIntegrationTest owns the send pipeline and webhooks; this file owns
 * what each write endpoint accepts and rejects.
 */
class UniOneAdminWritesTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private const string BASE = '/api/v1/admin/unione';

    private function key(array $attrs = []): UniOneApiKey
    {
        return UniOneApiKey::query()->create([
            'name' => 'Test key',
            'api_key' => 'original-secret-key',
            'key_type' => UniOneApiKey::TYPE_USER,
            'region' => UniOneApiKey::REGION_EU1,
            'is_active' => true,
            ...$attrs,
        ])->refresh();
    }

    // ── keys ─────────────────────────────────────────────────────────────────

    public function test_key_store_returns_422_listing_every_required_field(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::BASE.'/keys', [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The name field is required. (and 3 more errors)',
                'errors' => [
                    'name' => ['The name field is required.'],
                    'api_key' => ['The api key field is required.'],
                    'key_type' => ['The key type field is required.'],
                    'region' => ['The region field is required.'],
                ],
            ]);
    }

    public function test_key_store_requires_a_project_id_for_a_project_key(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::BASE.'/keys', [
            'name' => 'P', 'api_key' => 'k', 'key_type' => UniOneApiKey::TYPE_PROJECT, 'region' => UniOneApiKey::REGION_EU1,
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The project id field is required when key type is project.',
                'errors' => ['project_id' => ['The project id field is required when key type is project.']],
            ]);
    }

    public function test_key_store_creates_the_key_and_returns_201(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::BASE.'/keys', [
            'name' => 'Main', 'api_key' => 'secret-1234', 'key_type' => UniOneApiKey::TYPE_USER, 'region' => UniOneApiKey::REGION_US1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Main')
            ->assertJsonPath('data.region', UniOneApiKey::REGION_US1);

        $this->assertSame('secret-1234', UniOneApiKey::query()->sole()->api_key);
    }

    public function test_key_update_with_a_blank_api_key_keeps_the_secret_and_its_verification(): void
    {
        $this->actingAsAdmin();
        $key = $this->key();
        $key->forceFill(['last_verified_at' => now(), 'last_verify_status' => 'ok'])->save();

        $this->putJson(self::BASE."/keys/{$key->id}", [
            'name' => 'Renamed', 'api_key' => '', 'key_type' => UniOneApiKey::TYPE_USER, 'region' => UniOneApiKey::REGION_EU1,
        ])->assertOk()->assertJsonPath('data.name', 'Renamed');

        $key->refresh();
        $this->assertSame('original-secret-key', $key->api_key);
        $this->assertNotNull($key->last_verified_at);
    }

    /**
     * KNOWN BUG, pinned so a refactor cannot change it silently.
     *
     * UniOneKeyController::update() means to clear the verification when the
     * key is replaced, but `last_verified_at` / `last_verify_status` are not
     * fillable, so fill() discards the reset and a rotated key stays verified.
     * Fixing it should flip the last two assertions to assertNull.
     */
    public function test_key_update_with_a_new_api_key_currently_keeps_its_verification_known_bug(): void
    {
        $this->actingAsAdmin();
        $key = $this->key();
        $key->forceFill(['last_verified_at' => now(), 'last_verify_status' => 'ok'])->save();

        $this->putJson(self::BASE."/keys/{$key->id}", [
            'name' => 'Test key', 'api_key' => 'rotated-key', 'key_type' => UniOneApiKey::TYPE_USER, 'region' => UniOneApiKey::REGION_EU1,
        ])->assertOk();

        $key->refresh();
        $this->assertSame('rotated-key', $key->api_key);
        $this->assertNotNull($key->last_verified_at);
        $this->assertSame('ok', $key->last_verify_status);
    }

    public function test_key_update_returns_404_for_an_unknown_key_before_validating(): void
    {
        $this->actingAsAdmin();

        $this->putJson(self::BASE.'/keys/999', [])->assertNotFound();
    }

    public function test_domain_dns_returns_422_when_the_domain_is_missing(): void
    {
        $this->actingAsAdmin();
        $key = $this->key();

        $this->postJson(self::BASE."/keys/{$key->id}/domains/dns", [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The domain field is required.',
                'errors' => ['domain' => ['The domain field is required.']],
            ]);
    }

    public function test_recheck_domain_returns_422_for_an_unknown_check(): void
    {
        $this->actingAsAdmin();
        $key = $this->key();

        $this->postJson(self::BASE."/keys/{$key->id}/domains/recheck", ['domain' => 'example.test', 'check' => 'spf'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The selected check is invalid.',
                'errors' => ['check' => ['The selected check is invalid.']],
            ]);
    }

    // ── receivers ────────────────────────────────────────────────────────────

    public function test_receiver_store_returns_422_for_an_address_already_on_the_list(): void
    {
        $this->actingAsAdmin();
        UniOneReceiver::query()->create(['email' => 'taken@example.test']);

        $this->postJson(self::BASE.'/receivers', ['email' => 'taken@example.test'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The email has already been taken.',
                'errors' => ['email' => ['The email has already been taken.']],
            ]);
    }

    public function test_receiver_store_accepts_an_address_that_was_only_soft_deleted(): void
    {
        $this->actingAsAdmin();
        UniOneReceiver::query()->create(['email' => 'back@example.test'])->delete();

        $this->postJson(self::BASE.'/receivers', ['email' => 'again@example.test', 'name' => 'Again'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'again@example.test');
    }

    public function test_receiver_update_may_keep_its_own_address(): void
    {
        $this->actingAsAdmin();
        $receiver = UniOneReceiver::query()->create(['email' => 'self@example.test']);

        $this->putJson(self::BASE."/receivers/{$receiver->id}", ['email' => 'self@example.test', 'name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_receiver_bulk_status_returns_422_without_a_status(): void
    {
        $this->actingAsAdmin();
        $receiver = UniOneReceiver::query()->create(['email' => 'a@example.test']);

        $this->postJson(self::BASE.'/receivers/bulk', ['ids' => [$receiver->id], 'action' => 'status'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The status field is required when action is status.',
                'errors' => ['status' => ['The status field is required when action is status.']],
            ]);
    }

    public function test_receiver_bulk_suppress_suppresses_the_given_receivers(): void
    {
        $this->actingAsAdmin();
        $receiver = UniOneReceiver::query()->create(['email' => 'a@example.test']);

        $this->postJson(self::BASE.'/receivers/bulk', ['ids' => [$receiver->id], 'action' => 'suppress'])
            ->assertOk()
            ->assertExactJson(['data' => ['affected' => 1, 'action' => 'suppress']]);

        $this->assertSame(UniOneReceiver::STATUS_SUPPRESSED, $receiver->fresh()->status);
    }

    public function test_receiver_import_returns_422_without_a_file(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::BASE.'/receivers/import', [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The file field is required.',
                'errors' => ['file' => ['The file field is required.']],
            ]);
    }

    // ── sends ────────────────────────────────────────────────────────────────

    public function test_send_store_returns_422_listing_every_required_field(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::BASE.'/sends', [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The unione api key id field is required. (and 2 more errors)',
                'errors' => [
                    'unione_api_key_id' => ['The unione api key id field is required.'],
                    'count' => ['The count field is required.'],
                    'from_email' => ['The from email field is required.'],
                ],
            ]);
    }

    public function test_send_store_explains_a_missing_key_with_422(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::BASE.'/sends', ['unione_api_key_id' => 999, 'count' => 10, 'from_email' => 'from@example.test'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'No active UniOne key is available. Add one in the UniOne section and verify it before sending.',
            ]);
    }

    public function test_send_test_returns_422_listing_every_required_field(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::BASE.'/sends/test', [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The unione api key id field is required. (and 2 more errors)',
                'errors' => [
                    'unione_api_key_id' => ['The unione api key id field is required.'],
                    'email' => ['The email field is required.'],
                    'from_email' => ['The from email field is required.'],
                ],
            ]);
    }

    public function test_send_preview_returns_422_for_a_count_above_the_ceiling(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::BASE.'/sends/preview', ['count' => 100001])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The count field must not be greater than 100000.',
                'errors' => ['count' => ['The count field must not be greater than 100000.']],
            ]);
    }
}
