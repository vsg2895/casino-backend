<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailgunKey;
use App\Models\MailgunReceiver;
use App\Models\SmtpCredential;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The per-credential receiver-campaign endpoints, for BOTH channels.
 *
 * Mailgun keys and own SMTP servers expose the same five read/edit endpoints
 * over the same receiver list. They differ in exactly one response key:
 * Mailgun's settings carry `send_enabled` (first), SMTP's do not. Every test
 * runs against both, so the two cannot drift apart unnoticed.
 */
class ReceiverCampaignEndpointsTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function channels(): array
    {
        return ['mailgun key' => ['mailgun'], 'smtp credential' => ['smtp']];
    }

    private function credential(string $channel, array $attrs = []): MailgunKey|SmtpCredential
    {
        $shared = [
            'from_address' => 'news@mail.example.com',
            'from_name' => 'Test',
            'batch_size' => 100,
            'selection_order' => MailgunReceiver::ORDER_OLDEST,
            'status' => 'active',
            ...$attrs,
        ];

        return $channel === 'mailgun'
            ? MailgunKey::create(['name' => 'Mailgun', 'domain' => 'mail.example.com', 'api_key' => 'key-test', ...$shared])
            : SmtpCredential::create([
                'name' => 'Own SMTP', 'type' => 'smtp', 'host' => 'smtp.example.com', 'port' => 587,
                'username' => 'mailer@example.com', 'password' => 'secret', 'encryption' => 'tls', 'only_active' => false,
                ...$shared,
            ]);
    }

    private function url(Model $credential, string $suffix): string
    {
        $prefix = $credential instanceof MailgunKey ? 'mailgun-keys' : 'smtp-credentials';

        return "/api/v1/admin/{$prefix}/{$credential->id}/{$suffix}";
    }

    #[DataProvider('channels')]
    public function test_settings_return_the_channels_exact_keys_in_order(string $channel): void
    {
        $this->actingAsAdmin();
        $credential = $this->credential($channel);

        $keys = array_keys($this->getJson($this->url($credential, 'receiver-settings'))->assertOk()->json('data'));

        $expected = [
            'batch_size', 'selection_order', 'cooldown_days', 'receiver_sources', 'message_subject',
            'message_template', 'template_source', 'last_run_at', 'eligible_count', 'next_batch_count', 'blocked_reason',
        ];
        $this->assertSame($channel === 'mailgun' ? ['send_enabled', ...$expected] : $expected, $keys);
    }

    #[DataProvider('channels')]
    public function test_settings_return_the_saved_subject_once_one_is_configured(string $channel): void
    {
        $this->actingAsAdmin();
        $credential = $this->credential($channel, ['message_subject' => 'Saved subject', 'cooldown_days' => 3]);
        MailgunReceiver::create(['email' => 'a@example.test', 'source' => MailgunReceiver::SOURCE_MANUAL]);

        $this->getJson($this->url($credential, 'receiver-settings'))
            ->assertOk()
            ->assertJsonPath('data.message_subject', 'Saved subject')
            ->assertJsonPath('data.cooldown_days', 3)
            ->assertJsonPath('data.batch_size', 100)
            ->assertJsonPath('data.eligible_count', 1)
            ->assertJsonPath('data.next_batch_count', 1);
    }

    #[DataProvider('channels')]
    public function test_settings_explain_why_an_unconfigured_credential_cannot_run(string $channel): void
    {
        $this->actingAsAdmin();
        $credential = $this->credential($channel);

        $reason = $this->getJson($this->url($credential, 'receiver-settings'))->assertOk()->json('data.blocked_reason');

        $this->assertSame($channel === 'mailgun' ? 'sending disabled for this credential' : 'no message is configured for this credential', $reason);
    }

    #[DataProvider('channels')]
    public function test_update_renders_and_stores_the_message_and_reports_the_fresh_state(string $channel): void
    {
        $this->actingAsAdmin();
        $credential = $this->credential($channel);

        $this->putJson($this->url($credential, 'receiver-settings'), [
            // Mailgun's settings request requires the switch; SMTP has none.
            ...($channel === 'mailgun' ? ['send_enabled' => false] : []),
            'batch_size' => 50,
            'selection_order' => MailgunReceiver::ORDER_OLDEST,
            'message_subject' => 'Spring offers',
            'message_template' => ['heading' => 'Hello there', 'intro_text' => 'New bonuses this week.'],
        ])
            ->assertOk()
            ->assertExactJson(['data' => [
                'eligible_count' => 0,
                'next_batch_count' => 0,
                'blocked_reason' => $channel === 'mailgun' ? 'sending disabled for this credential' : null,
            ]]);

        $credential->refresh();
        $this->assertSame(50, (int) $credential->batch_size);
        $this->assertSame('Spring offers', $credential->message_subject);
        $this->assertStringContainsString('Hello there', (string) $credential->message_html);
    }

    #[DataProvider('channels')]
    public function test_template_source_returns_the_seed_fields(string $channel): void
    {
        $this->actingAsAdmin();
        $credential = $this->credential($channel);

        $data = $this->getJson($this->url($credential, 'receiver-template-source'))->assertOk()->json('data');

        $this->assertSame(['subject', 'template', 'site_name'], array_keys($data));
    }

    #[DataProvider('channels')]
    public function test_message_preview_returns_422_for_an_invalid_button_url(string $channel): void
    {
        $this->actingAsAdmin();
        $credential = $this->credential($channel);

        $this->postJson($this->url($credential, 'receiver-message-preview'), ['message_template' => ['button_url' => 'not a url']])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The message template.button url field must be a valid URL.',
                'errors' => ['message_template.button_url' => ['The message template.button url field must be a valid URL.']],
            ]);
    }

    #[DataProvider('channels')]
    public function test_message_preview_renders_unsaved_fields_without_writing_them(string $channel): void
    {
        $this->actingAsAdmin();
        $credential = $this->credential($channel);

        $html = $this->postJson($this->url($credential, 'receiver-message-preview'), [
            'message_template' => ['heading' => 'Preview heading', 'intro_text' => 'Body'],
        ])->assertOk()->json('html');

        $this->assertStringContainsString('Preview heading', $html);
        $this->assertNull($credential->fresh()->message_template);
    }

    #[DataProvider('channels')]
    public function test_batch_preview_lists_the_next_receivers_with_counts(string $channel): void
    {
        $this->actingAsAdmin();
        $credential = $this->credential($channel);
        MailgunReceiver::create(['email' => 'next@example.test', 'source' => MailgunReceiver::SOURCE_MANUAL]);

        $this->getJson($this->url($credential, 'receiver-preview'))
            ->assertOk()
            ->assertJsonPath('data.0.email', 'next@example.test')
            ->assertJsonPath('meta', ['eligible_count' => 1, 'next_batch_count' => 1]);
    }
}
