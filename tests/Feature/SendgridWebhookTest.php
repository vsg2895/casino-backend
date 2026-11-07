<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailgunReceiver;
use App\Models\MailgunSuppression;
use App\Models\SendgridKey;
use App\Models\SendgridReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

/**
 * The SendGrid Event Webhook: signed batches of events, of which only hard
 * bounces, spam reports and unsubscribes retire an address.
 *
 * Signed for real with a throwaway P-256 key, so the test exercises the same
 * openssl_verify() path SendGrid's signature takes in production.
 */
class SendgridWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const string URL = '/api/v1/sendgrid-webhook';

    private OpenSSLAsymmetricKey $privateKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->privateKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $pem = openssl_pkey_get_details($this->privateKey)['key'];

        // Stored the way SendGrid displays it: bare base64, no PEM armour.
        $bare = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);

        SendgridKey::create([
            'name'                     => 'SendGrid',
            'api_key'                  => 'SG.test-key-value-long-enough',
            'webhook_verification_key' => $bare,
            'status'                   => 'active',
        ]);
    }

    /** @param  list<array<string, mixed>>  $events */
    private function send(array $events, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($events, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        if ($signature === null) {
            openssl_sign($timestamp . $body, $raw, $this->privateKey, OPENSSL_ALGO_SHA256);
            $signature = base64_encode($raw);
        }

        return $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE'                                  => 'application/json',
            'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE'   => $signature,
            'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP'   => $timestamp,
        ], $body);
    }

    public function test_a_hard_bounce_suppresses_the_address_on_both_lists(): void
    {
        $receiver = SendgridReceiver::create(['email' => 'dead@example.com', 'source' => SendgridReceiver::SOURCE_MANUAL]);
        MailgunReceiver::create(['email' => 'dead@example.com', 'source' => MailgunReceiver::SOURCE_MANUAL]);

        $this->send([['email' => 'Dead@Example.com', 'event' => 'bounce', 'type' => 'bounce']])
            ->assertOk()
            ->assertJsonPath('suppressed', 1);

        $this->assertSame(MailgunSuppression::REASON_BOUNCE, MailgunSuppression::query()->where('email', 'dead@example.com')->value('reason'));
        $this->assertFalse($receiver->fresh()->is_active);
        $this->assertSame(0, SendgridReceiver::query()->sendable()->notSuppressed()->count());
        $this->assertSame(0, MailgunReceiver::query()->sendable()->notSuppressed()->count());
    }

    public function test_spam_reports_unsubscribes_and_dead_drops_are_suppressed_with_their_reason(): void
    {
        $this->send([
            ['email' => 'spam@example.com', 'event' => 'spamreport'],
            ['email' => 'leave@example.com', 'event' => 'group_unsubscribe'],
            ['email' => 'gone@example.com', 'event' => 'dropped', 'reason' => 'Bounced Address'],
        ])->assertOk()->assertJsonPath('suppressed', 3);

        $this->assertSame(
            ['gone@example.com' => 'bounce', 'leave@example.com' => 'unsubscribe', 'spam@example.com' => 'complaint'],
            MailgunSuppression::query()->orderBy('email')->pluck('reason', 'email')->all(),
        );
    }

    public function test_soft_bounces_and_ordinary_events_suppress_nobody(): void
    {
        $this->send([
            ['email' => 'busy@example.com', 'event' => 'bounce', 'type' => 'blocked'],
            ['email' => 'busy@example.com', 'event' => 'deferred'],
            ['email' => 'ok@example.com', 'event' => 'delivered'],
            ['email' => 'msg@example.com', 'event' => 'dropped', 'reason' => 'Invalid SMTPAPI header'],
        ])->assertOk()->assertJsonPath('suppressed', 0);

        $this->assertSame(0, MailgunSuppression::count());
    }

    public function test_an_unsigned_or_forged_batch_is_refused_and_changes_nothing(): void
    {
        $events = [['email' => 'victim@example.com', 'event' => 'bounce', 'type' => 'bounce']];

        $this->send($events, base64_encode('not a signature'))->assertForbidden();
        $this->postJson(self::URL, $events)->assertForbidden();

        $this->assertSame(0, MailgunSuppression::count());
    }

    public function test_nothing_is_accepted_when_no_key_is_configured(): void
    {
        SendgridKey::query()->update(['webhook_verification_key' => null]);

        $this->send([['email' => 'x@example.com', 'event' => 'bounce', 'type' => 'bounce']])->assertForbidden();

        $this->assertSame(0, MailgunSuppression::count());
    }
}
