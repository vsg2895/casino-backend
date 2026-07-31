<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\NewsletterSubscribedMail;
use App\Mail\PromotionEmail;
use App\Mail\VerifyEmailMail;
use App\Models\EmailSchedule;
use App\Models\SendgridKey;
use App\Models\Site;
use App\Services\Mail\EmailTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class SendgridKeyTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private const string PLAIN_KEY = 'SG.test-key-abcdefghijklmnop.qrstuvwxyz123456';

    private function makeKey(array $attrs = []): SendgridKey
    {
        return SendgridKey::create([
            'name'    => 'Main key',
            'api_key' => self::PLAIN_KEY,
            'status'  => SendgridKey::STATUS_ACTIVE,
            ...$attrs,
        ]);
    }

    // ── Security invariants ───────────────────────────────────────────────

    public function test_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/admin/sendgrid-keys')->assertUnauthorized();
        $this->postJson('/api/v1/admin/sendgrid-keys')->assertUnauthorized();
    }

    public function test_key_is_stored_encrypted_and_never_returned_raw(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/sendgrid-keys', [
            'name'    => 'Prod key',
            'api_key' => self::PLAIN_KEY,
        ])->assertCreated();

        // The response exposes only the masked preview, never the raw key.
        $response->assertJsonMissingPath('data.api_key');
        $this->assertStringNotContainsString(self::PLAIN_KEY, $response->getContent());
        $this->assertStringStartsWith('SG.tes', (string) $response->json('data.masked_key'));

        // At rest the column holds ciphertext, not the plaintext…
        $raw = DB::table('sendgrid_keys')->value('api_key');
        $this->assertNotSame(self::PLAIN_KEY, $raw);
        $this->assertStringNotContainsString(self::PLAIN_KEY, (string) $raw);

        // …but the model cast decrypts it back for the mailer.
        $this->assertSame(self::PLAIN_KEY, SendgridKey::first()->api_key);
    }

    // ── CRUD ──────────────────────────────────────────────────────────────

    public function test_create_validates_name_and_key(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/sendgrid-keys', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'api_key']);

        // Obviously-too-short keys are rejected.
        $this->postJson('/api/v1/admin/sendgrid-keys', ['name' => 'x', 'api_key' => 'short'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('api_key');
    }

    public function test_index_lists_keys_and_filters_by_status(): void
    {
        $this->actingAsAdmin();
        $this->makeKey(['name' => 'Active one']);
        $this->makeKey(['name' => 'Paused one', 'status' => SendgridKey::STATUS_INACTIVE]);

        $this->getJson('/api/v1/admin/sendgrid-keys')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/admin/sendgrid-keys?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active one');
    }

    public function test_update_without_api_key_keeps_the_stored_key(): void
    {
        $this->actingAsAdmin();
        $key = $this->makeKey();

        // Renaming with a blank key must not wipe the stored secret.
        $this->putJson("/api/v1/admin/sendgrid-keys/{$key->id}", [
            'name'    => 'Renamed',
            'api_key' => '',
        ])->assertOk()->assertJsonPath('data.name', 'Renamed');

        $this->putJson("/api/v1/admin/sendgrid-keys/{$key->id}", [
            'name'    => 'Renamed again',
            'api_key' => null,
        ])->assertOk();

        $this->assertSame(self::PLAIN_KEY, $key->fresh()->api_key);
    }

    public function test_update_with_a_new_api_key_rotates_it(): void
    {
        $this->actingAsAdmin();
        $key = $this->makeKey();
        $newKey = 'SG.rotated-key-0123456789abcdef.ghijklmnopqrstuv';

        $this->putJson("/api/v1/admin/sendgrid-keys/{$key->id}", [
            'name'    => $key->name,
            'api_key' => $newKey,
        ])->assertOk();

        $this->assertSame($newKey, $key->fresh()->api_key);
    }

    public function test_toggle_flips_status_without_touching_the_key(): void
    {
        $this->actingAsAdmin();
        $key = $this->makeKey();

        $this->patchJson("/api/v1/admin/sendgrid-keys/{$key->id}/toggle")
            ->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->patchJson("/api/v1/admin/sendgrid-keys/{$key->id}/toggle")
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame(self::PLAIN_KEY, $key->fresh()->api_key);
    }

    // ── "Send test" — does this key actually work? ────────────────────────

    /** Valid test payload: template + website + recipient. */
    private function testPayload(Site $site, string $template = 'promotion', string $to = 'admin@example.com'): array
    {
        return ['to' => $to, 'site_id' => $site->id, 'template' => $template];
    }

    public function test_test_send_requires_auth(): void
    {
        [$site] = $this->siteWithKey();
        $key = $this->makeKey();

        $this->postJson("/api/v1/admin/sendgrid-keys/{$key->id}/test", $this->testPayload($site))
            ->assertUnauthorized();
    }

    public function test_test_send_requires_recipient_template_and_website(): void
    {
        $this->actingAsAdmin();
        $key = $this->makeKey();

        // Nothing selected → every required field reported at once.
        $this->postJson("/api/v1/admin/sendgrid-keys/{$key->id}/test", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to', 'site_id', 'template']);
    }

    public function test_test_send_rejects_invalid_values(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $key = $this->makeKey();

        $this->postJson("/api/v1/admin/sendgrid-keys/{$key->id}/test", [
            'to' => 'not-an-email', 'site_id' => $site->id, 'template' => 'promotion',
        ])->assertStatus(422)->assertJsonValidationErrorFor('to');

        $this->postJson("/api/v1/admin/sendgrid-keys/{$key->id}/test", [
            'to' => 'a@example.com', 'site_id' => 999999, 'template' => 'promotion',
        ])->assertStatus(422)->assertJsonValidationErrorFor('site_id');

        $this->postJson("/api/v1/admin/sendgrid-keys/{$key->id}/test", [
            'to' => 'a@example.com', 'site_id' => $site->id, 'template' => 'no-such-template',
        ])->assertStatus(422)->assertJsonValidationErrorFor('template');
    }

    /**
     * Each catalog template must send ITS OWN mailable through the key under
     * test — never a generic message and never the .env SMTP mailer.
     */
    public static function templateProvider(): array
    {
        return [
            'subscribe' => ['subscribe', NewsletterSubscribedMail::class],
            'verify'    => ['verify', VerifyEmailMail::class],
            'promotion' => ['promotion', PromotionEmail::class],
        ];
    }

    #[DataProvider('templateProvider')]
    public function test_the_selected_template_is_sent_through_the_key(string $template, string $mailable): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $key = $this->makeKey();

        $this->postJson("/api/v1/admin/sendgrid-keys/{$key->id}/test", $this->testPayload($site, $template))
            ->assertOk()
            ->assertJsonPath('ok', true);

        Mail::assertSent(
            $mailable,
            fn ($mail): bool => $mail->hasTo('admin@example.com') && $mail->mailer === "sendgrid_key_{$key->id}",
        );
    }

    public function test_the_test_email_uses_the_templates_own_sender(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $key = $this->makeKey();

        // The site's own promotion sender…
        $site->promotionEmailOrDefault()->update([
            'from_email' => 'offers@chosen-site.test',
            'from_name'  => 'Chosen Site Offers',
        ]);
        // …must win over the platform's shared verified sender.
        config()->set('mail.public_from_address', 'shared@platform.test');

        $this->postJson("/api/v1/admin/sendgrid-keys/{$key->id}/test", $this->testPayload($site))
            ->assertOk();

        Mail::assertSent(PromotionEmail::class, function ($mail): bool {
            $from = $mail->envelope()->from;

            return $from->address === 'offers@chosen-site.test'
                && $from->name === 'Chosen Site Offers';
        });
    }

    public function test_each_template_keeps_its_own_sender(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $key = $this->makeKey();
        config()->set('mail.public_from_address', 'shared@platform.test');

        $site->verifyEmailOrDefault()->update(['from_email' => 'verify@chosen-site.test']);
        $site->emailTemplateOrDefault()->update(['from_email' => 'welcome@chosen-site.test']);

        $this->postJson("/api/v1/admin/sendgrid-keys/{$key->id}/test", $this->testPayload($site, 'verify'))
            ->assertOk();
        $this->postJson("/api/v1/admin/sendgrid-keys/{$key->id}/test", $this->testPayload($site, 'subscribe'))
            ->assertOk();

        Mail::assertSent(
            VerifyEmailMail::class,
            fn ($mail): bool => $mail->envelope()->from->address === 'verify@chosen-site.test',
        );
        Mail::assertSent(
            NewsletterSubscribedMail::class,
            fn ($mail): bool => $mail->envelope()->from->address === 'welcome@chosen-site.test',
        );
    }

    public function test_the_template_is_rendered_for_the_selected_website(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$siteA] = $this->siteWithKey();
        [$siteB] = $this->siteWithKey();
        $key = $this->makeKey();

        $this->postJson("/api/v1/admin/sendgrid-keys/{$key->id}/test", $this->testPayload($siteB))
            ->assertOk()
            ->assertJsonFragment(['ok' => true]);

        // The recipient is registered against the CHOSEN site, so the rendered
        // template carries that site's tokens — not the other site's.
        $this->assertDatabaseHas('newsletters', ['site_id' => $siteB->id, 'email' => 'admin@example.com']);
        $this->assertDatabaseMissing('newsletters', ['site_id' => $siteA->id, 'email' => 'admin@example.com']);
    }

    public function test_an_inactive_key_can_still_be_tested(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        // Verifying a key BEFORE enabling it is a legitimate workflow.
        $key = $this->makeKey(['status' => SendgridKey::STATUS_INACTIVE]);

        $this->postJson("/api/v1/admin/sendgrid-keys/{$key->id}/test", $this->testPayload($site))
            ->assertOk()->assertJsonPath('ok', true);

        Mail::assertSent(PromotionEmail::class, 1);
    }

    public function test_a_broken_key_reports_the_transport_error(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        // A key whose stored value is unusable cannot authenticate.
        $key = SendgridKey::create(['name' => 'Empty', 'api_key' => '', 'status' => SendgridKey::STATUS_ACTIVE]);

        $this->postJson("/api/v1/admin/sendgrid-keys/{$key->id}/test", $this->testPayload($site))
            ->assertStatus(502)
            ->assertJsonPath('ok', false)
            ->assertJsonFragment(['message' => 'Key test failed: SendGrid key #' . $key->id . ' has an empty value; cannot authenticate.']);

        Mail::assertNothingSent();
    }

    public function test_test_send_never_leaks_the_raw_key(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $key = $this->makeKey();

        $response = $this->postJson("/api/v1/admin/sendgrid-keys/{$key->id}/test", $this->testPayload($site));

        $this->assertStringNotContainsString(self::PLAIN_KEY, $response->getContent());
    }

    // ── Template catalog (drives the dropdown) ────────────────────────────

    public function test_template_types_endpoint_lists_the_catalog(): void
    {
        $this->getJson('/api/v1/admin/email-template-types')->assertUnauthorized();

        $this->actingAsAdmin();
        $response = $this->getJson('/api/v1/admin/email-template-types')
            ->assertOk()
            ->assertJsonStructure(['data' => [['value', 'label', 'description']]]);

        // Every advertised value must be accepted by the send validation.
        $values = array_column($response->json('data'), 'value');
        $this->assertSame(app(EmailTemplateCatalog::class)->keys(), $values);
        $this->assertContains('promotion', $values);
        $this->assertContains('verify', $values);
        $this->assertContains('subscribe', $values);
    }

    public function test_deleting_a_key_detaches_it_from_schedules(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $key = $this->makeKey();

        $schedule = EmailSchedule::create([
            'site_id'         => $site->id,
            'date_filter'     => EmailSchedule::FILTER_TODAY,
            'frequency'       => EmailSchedule::FREQ_DAILY,
            'time'            => '03:00',
            'active'          => true,
            'provider'        => EmailSchedule::PROVIDER_SENDGRID,
            'sendgrid_key_id' => $key->id,
        ]);

        $this->deleteJson("/api/v1/admin/sendgrid-keys/{$key->id}")->assertNoContent();

        // FK nullOnDelete: the schedule survives, its key reference is cleared.
        $this->assertDatabaseMissing('sendgrid_keys', ['id' => $key->id]);
        $this->assertNull($schedule->fresh()->sendgrid_key_id);
    }
}
