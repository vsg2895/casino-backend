<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\Unsubscribe;
use App\Services\Mail\Transport\SendgridClickTrackingClient;
use App\Services\PromotionEmailService;
use App\Services\SubscriptionEmailService;
use App\Services\VerifyEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The verification email's transport-level behaviour.
 *
 * NO REAL EMAIL IS SENT. The SendGrid transport is real, but its HTTP client is a
 * MockHttpClient, so the /v3/mail/send request is captured instead of dispatched.
 * That is deliberate: asserting against the ACTUAL outgoing payload is the only
 * way to prove the click-tracking setting survives Symfony's payload builder.
 *
 * Two properties are pinned here, and the second matters as much as the first:
 *
 *  1. the verification email disables SendGrid click tracking and carries the
 *     RFC 8058 one-click headers built from the EXISTING unsubscribe token;
 *  2. no other email type is affected — same mailer, same transport, unchanged
 *     payload.
 */
class VerificationEmailTrackingTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /**
     * Send a mailable through the real SendGrid transport and return the JSON
     * body it would have POSTed.
     *
     * @return array<string, mixed>
     */
    private function capturePayload(Mailable $mailable, string $to = 'fan@example.com'): array
    {
        $captured = [];

        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options['json']
                ?? (array) json_decode((string) ($options['body'] ?? '{}'), true);

            // SendGrid answers 202 and the transport reads this header back.
            return new MockResponse('', [
                'http_code'        => 202,
                'response_headers' => ['x-message-id' => 'mock-message-id'],
            ]);
        });

        // The same wiring AppServiceProvider uses for the real `sendgrid` mailer,
        // with the network swapped out — so the decorator under test sits exactly
        // where it sits in production.
        Mail::extend('sendgrid-capture', fn (array $config) => (new SendgridTransportFactory(
            client: new SendgridClickTrackingClient($mock),
        ))->create(new Dsn('sendgrid+api', 'default', 'test-key')));

        config()->set('mail.mailers.sendgrid-capture', ['transport' => 'sendgrid-capture']);

        Mail::mailer('sendgrid-capture')->to($to)->send($mailable);

        return $captured;
    }

    /** The verification mailable for a real subscriber, as production builds it. */
    private function verificationMail(): array
    {
        [$site] = $this->siteWithKey(['domain' => 'winpalack.com']);
        $subscriber = Newsletter::create(['site_id' => $site->id, 'email' => 'fan@example.com']);

        return [$site, $subscriber, app(VerifyEmailService::class)->mailForSubscriber($site, $subscriber)];
    }

    /** @return array<string, string> */
    private function headerLines(Mailable $mailable): array
    {
        $lines = [];

        foreach ($mailable->headers()->text as $name => $value) {
            $lines[strtolower($name)] = $value;
        }

        return $lines;
    }

    // ── Task 2: List-Unsubscribe headers ─────────────────────────────────────

    public function test_verification_email_carries_one_click_headers_with_the_verify_token(): void
    {
        [, $subscriber, $mailable] = $this->verificationMail();

        $headers = $this->headerLines($mailable);

        // The token is the one ALREADY on the subscriber for the verify stream —
        // no new token, route or table was introduced for this.
        $expectedToken = $subscriber->unsubscribeTokenFor(Unsubscribe::TYPE_VERIFY);

        $this->assertArrayHasKey('list-unsubscribe', $headers);
        $this->assertArrayHasKey('list-unsubscribe-post', $headers);

        // Angle brackets are mandatory; without them the header is invalid.
        $this->assertStringStartsWith('<', $headers['list-unsubscribe']);
        $this->assertStringEndsWith('>', $headers['list-unsubscribe']);

        $this->assertStringContainsString($expectedToken, $headers['list-unsubscribe']);

        // Served from the SITE's domain, not the API host — this is the only
        // stream that does. See Unsubscribe::siteOneClickUrl().
        $this->assertSame(
            '<https://winpalack.com/api/unsubscribe/' . $expectedToken . '>',
            $headers['list-unsubscribe'],
        );

        // No mailto part, per the brief.
        $this->assertStringNotContainsString('mailto:', $headers['list-unsubscribe']);

        $this->assertSame('List-Unsubscribe=One-Click', $headers['list-unsubscribe-post']);
    }

    public function test_the_one_click_url_resolves_to_the_post_only_endpoint(): void
    {
        [, $subscriber, $mailable] = $this->verificationMail();

        $url = trim($this->headerLines($mailable)['list-unsubscribe'], '<>');

        // The header points at the site's thin route handler, which forwards to
        // this endpoint with the same token. Asserting the upstream contract here
        // is what proves the hop cannot change the outcome.
        $token = basename((string) parse_url($url, PHP_URL_PATH));
        $path = '/api/v1/unsubscribe/' . $token;

        // GET on this path is refused (405) — see Task 3 — so a link scanner
        // following the header cannot unsubscribe anyone.
        $this->get($path)->assertStatus(405);
        $this->assertDatabaseCount('unsubscribes', 0);

        // The same path accepts the provider's one-click POST.
        $this->postJson($path)->assertOk();
        $this->assertTrue(Unsubscribe::has(
            $subscriber->site_id,
            $subscriber->email,
            Unsubscribe::TYPE_VERIFY,
        ));
    }

    // ── Task 1: click tracking off, in the request itself ────────────────────

    public function test_verification_email_disables_click_tracking_in_the_payload(): void
    {
        [, , $mailable] = $this->verificationMail();

        $payload = $this->capturePayload($mailable);

        $this->assertFalse($payload['tracking_settings']['click_tracking']['enable']);
        // enable_text matters as much: the verification email prints the
        // confirmation URL as pasted text as well as in the button.
        $this->assertFalse($payload['tracking_settings']['click_tracking']['enable_text']);
    }

    public function test_the_marker_header_is_stripped_before_the_request_leaves(): void
    {
        [, , $mailable] = $this->verificationMail();

        $payload = $this->capturePayload($mailable);

        $names = array_map('strtolower', array_keys($payload['headers'] ?? []));

        $this->assertNotContains(
            strtolower(SendgridClickTrackingClient::DISABLE_HEADER),
            $names,
            'the internal marker must never reach SendGrid',
        );

        // …while the headers that SHOULD travel still do.
        $this->assertContains('list-unsubscribe', $names);
        $this->assertContains('list-unsubscribe-post', $names);
    }

    // ── Task 4: subscription tracking must never be enabled ──────────────────

    public function test_verification_payload_never_enables_subscription_tracking(): void
    {
        [, , $mailable] = $this->verificationMail();

        $payload = $this->capturePayload($mailable);

        // SendGrid replaces our List-Unsubscribe header with its own when
        // subscription tracking is on, which would route opt-outs into its
        // suppression list instead of the `unsubscribes` table every send gates on.
        $this->assertArrayNotHasKey('subscription_tracking', $payload['tracking_settings'] ?? []);
    }

    // ── Scope: no other email type is affected ───────────────────────────────

    public function test_other_email_types_send_with_no_tracking_settings_at_all(): void
    {
        [$site] = $this->siteWithKey();
        $subscriber = Newsletter::create(['site_id' => $site->id, 'email' => 'other@example.com']);

        $subscription = app(SubscriptionEmailService::class)->mailForSubscriber($site, $subscriber);
        $promotion = app(PromotionEmailService::class)
            ->mailForSubscriber($site, $site->promotionEmailOrDefault(), $subscriber);

        foreach (['subscription' => $subscription, 'promotion' => $promotion] as $label => $mailable) {
            $payload = $this->capturePayload($mailable, 'other@example.com');

            // Absent, not false: these streams keep following the SendGrid
            // account settings exactly as before this change.
            $this->assertArrayNotHasKey(
                'tracking_settings',
                $payload,
                "{$label} email must not carry tracking settings",
            );
        }
    }

    public function test_other_email_types_do_not_carry_the_marker_header(): void
    {
        [$site] = $this->siteWithKey();
        $subscriber = Newsletter::create(['site_id' => $site->id, 'email' => 'other@example.com']);

        $mailables = [
            'subscription' => app(SubscriptionEmailService::class)->mailForSubscriber($site, $subscriber),
            'promotion'    => app(PromotionEmailService::class)
                ->mailForSubscriber($site, $site->promotionEmailOrDefault(), $subscriber),
        ];

        foreach ($mailables as $label => $mailable) {
            $headers = $this->headerLines($mailable);

            $this->assertArrayNotHasKey(
                strtolower(SendgridClickTrackingClient::DISABLE_HEADER),
                $headers,
                "{$label} email must not opt out of click tracking",
            );

            // …and their own one-click headers are untouched.
            $this->assertArrayHasKey('list-unsubscribe', $headers);
            $this->assertArrayHasKey('list-unsubscribe-post', $headers);
        }
    }

    public function test_other_streams_keep_the_api_host_one_click_url(): void
    {
        // Only the verification email moved to the site domain. Changing the
        // header on the subscription / promotion / post-verification streams was
        // explicitly out of scope, and this is the guard for that.
        [$site] = $this->siteWithKey(['domain' => 'winpalack.com']);
        $subscriber = Newsletter::create(['site_id' => $site->id, 'email' => 'other@example.com']);

        $mailables = [
            'subscription' => app(SubscriptionEmailService::class)->mailForSubscriber($site, $subscriber),
            'promotion'    => app(PromotionEmailService::class)
                ->mailForSubscriber($site, $site->promotionEmailOrDefault(), $subscriber),
        ];

        foreach ($mailables as $label => $mailable) {
            $header = $this->headerLines($mailable)['list-unsubscribe'];

            $this->assertStringContainsString(
                '/api/v1/unsubscribe/',
                $header,
                "{$label} email must still point at the API host",
            );
            $this->assertStringNotContainsString(
                'winpalack.com/api/unsubscribe/',
                $header,
                "{$label} email must not adopt the site-domain URL",
            );
        }
    }

    // ── Task 3: GET must never unsubscribe ───────────────────────────────────

    public function test_post_unsubscribes_the_subscriber_and_returns_200(): void
    {
        [$site] = $this->siteWithKey();
        $subscriber = Newsletter::create(['site_id' => $site->id, 'email' => 'fan@example.com']);
        $token = $subscriber->unsubscribeTokenFor(Unsubscribe::TYPE_VERIFY);

        $this->postJson('/api/v1/unsubscribe/' . $token)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertTrue(Unsubscribe::has($site->id, 'fan@example.com', Unsubscribe::TYPE_VERIFY));
    }

    public function test_get_does_not_unsubscribe_the_subscriber(): void
    {
        // Anti-spam scanners GET every link in a message. If that unsubscribed
        // anyone, a single scan would silently empty the list.
        [$site] = $this->siteWithKey();
        $subscriber = Newsletter::create(['site_id' => $site->id, 'email' => 'fan@example.com']);
        $token = $subscriber->unsubscribeTokenFor(Unsubscribe::TYPE_VERIFY);

        $this->get('/api/v1/unsubscribe/' . $token)->assertStatus(405);

        $this->assertDatabaseCount('unsubscribes', 0);
        $this->assertFalse(Unsubscribe::hasAny($site->id, 'fan@example.com'));
    }

    public function test_post_with_an_unknown_token_still_returns_200(): void
    {
        // Never reveal whether an address is on a list, and never fail a
        // provider's one-click request.
        $this->postJson('/api/v1/unsubscribe/' . str_repeat('a', 64))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseCount('unsubscribes', 0);
    }
}
