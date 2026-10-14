<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UniOne\UniOneApiKey;
use App\Models\UniOne\UniOneReceiver;
use App\Services\UniOne\UniOneWebhookRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * UniOne delivery events: getting them, and acting on them.
 *
 * The reported fault was that the UniOne dashboard counted failures while the
 * admin's receiver list showed no bounced or complained addresses. The receiving
 * side was never broken — the endpoint, its token gate, the MD5 check, the
 * de-duplication and the status mapping all worked. What was missing was the
 * step that starts it: nothing ever called `webhook/set.json`, so UniOne had no
 * URL to post to.
 *
 * These tests pin both halves, because either one failing reproduces the same
 * silent symptom:
 *
 *   1. REGISTRATION — the platform asks UniOne for the right events, at the URL
 *      the admin displays, and records what it was told.
 *   2. INGESTION — an arriving `hard_bounced` makes a receiver bounced and a
 *      `spam` makes it complained, so the list stops mailing them.
 *
 * Nothing here touches the send path.
 */
class UniOneWebhookDeliveryTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** Stands in for `auth` while the hash over the body is computed. */
    private const string AUTH_PLACEHOLDER = '0000000000000000000000000000000x';

    private function key(): UniOneApiKey
    {
        return UniOneApiKey::create([
            'name'     => 'Primary',
            'api_key'  => 'secret-api-key',
            'key_type' => 'user',
            'region'   => 'eu1',
            'is_active' => true,
        ]);
    }

    private function receiver(string $email): UniOneReceiver
    {
        return UniOneReceiver::create(['email' => $email]);
    }

    /**
     * A payload in the shape UniOne posts, signed the way it signs.
     *
     * Named `postEvent`, not `post`: TestCase::post() is public, and a private
     * override of it is a fatal error before a single test runs.
     */
    private function postEvent(UniOneApiKey $key, string $email, string $status): \Illuminate\Testing\TestResponse
    {
        $body = json_encode([
            'auth'           => self::AUTH_PLACEHOLDER,
            'events_by_user' => [[
                'user_id' => 1,
                'events'  => [[
                    'event_name' => 'transactional_email_status',
                    'event_data' => [
                        'job_id'     => 'job-' . $status,
                        'email'      => $email,
                        'status'     => $status,
                        'event_time' => '2026-10-13 10:00:00',
                        'delivery_info' => [
                            'delivery_status'      => $status,
                            'destination_response' => '550 mailbox not found',
                        ],
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        /*
         * The documented hash: MD5 of the body with the `auth` VALUE replaced by
         * the API key, written back into `auth`. Computed here the same way the
         * verifier computes it, over the same raw bytes — re-encoding the array
         * would reorder keys and the hash would never match.
         */
        $withKey = str_replace(self::AUTH_PLACEHOLDER, (string) $key->api_key, $body);
        $signed = str_replace(self::AUTH_PLACEHOLDER, md5($withKey), $body);

        return $this->call(
            'POST',
            '/api/v1/unione/webhook/' . $key->webhook_secret,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $signed,
        );
    }

    // ── 1. registration ──────────────────────────────────────────────────────

    public function test_registering_asks_unione_for_the_events_the_platform_acts_on(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $key = $this->key();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/unione/keys/{$key->id}/webhook")
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        Http::assertSent(function ($request) use ($key): bool {
            if (! str_contains($request->url(), 'webhook/set.json')) {
                return false;
            }

            $body = $request->data();

            // The URL UniOne is told about must be the one the admin shows.
            $this->assertStringContainsString("/api/v1/unione/webhook/{$key->webhook_secret}", $body['url']);

            // And the events must include the two that change eligibility, or
            // bounced and complained addresses keep being mailed.
            $this->assertContains('hard_bounced', $body['events']['email_status']);
            $this->assertContains('spam', $body['events']['email_status']);
            $this->assertSame('active', $body['status']);

            return true;
        });

        $key->refresh();
        $this->assertNotNull($key->webhook_registered_at, 'a successful registration must be recorded');
    }

    public function test_a_refused_registration_keeps_the_providers_own_message(): void
    {
        // The worst outcome is a silent failure, so the operator gets the
        // provider's words rather than "registration failed".
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'api_key is invalid'], 400)]);

        $key = $this->key();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/unione/keys/{$key->id}/webhook")
            ->assertStatus(422)
            ->assertJsonPath('data.ok', false);

        $key->refresh();
        $this->assertNull($key->webhook_registered_at, 'a failure must not look like a registration');
        $this->assertStringContainsString('api_key is invalid', (string) $key->webhook_status);
    }

    // ── 2. ingestion ─────────────────────────────────────────────────────────

    public function test_a_hard_bounce_marks_the_receiver_bounced(): void
    {
        $key = $this->key();
        $receiver = $this->receiver('bounced@example.com');

        $this->postEvent($key, 'bounced@example.com', 'hard_bounced')->assertOk();

        $receiver->refresh();
        $this->assertSame(UniOneReceiver::STATUS_BOUNCED, $receiver->status);
        $this->assertSame(1, (int) $receiver->bounce_count);
    }

    public function test_a_spam_complaint_marks_the_receiver_complained(): void
    {
        $key = $this->key();
        $receiver = $this->receiver('angry@example.com');

        $this->postEvent($key, 'angry@example.com', 'spam')->assertOk();

        $receiver->refresh();
        $this->assertSame(UniOneReceiver::STATUS_COMPLAINED, $receiver->status);
        $this->assertSame(1, (int) $receiver->complaint_count);
    }

    public function test_a_soft_bounce_does_not_change_the_status(): void
    {
        // A soft failure describes the moment, not the mailbox.
        $key = $this->key();
        // Read back from the row: the column default applies on insert, so the
        // in-memory model does not carry it.
        $receiver = $this->receiver('full@example.com')->fresh();
        $before = $receiver->status;

        $this->postEvent($key, 'full@example.com', 'soft_bounced')->assertOk();

        $receiver->refresh();
        $this->assertSame($before, $receiver->status);
        $this->assertNotNull($receiver->retry_after);
    }

    public function test_an_arriving_event_stamps_the_key(): void
    {
        /*
         * The proof-of-life column. A registration can succeed and still deliver
         * nothing — a wrong URL, a firewall — and without this there is no way
         * to tell that apart from "nothing has bounced".
         */
        $key = $this->key();
        $this->receiver('someone@example.com');

        $this->assertNull($key->last_webhook_event_at);

        $this->postEvent($key, 'someone@example.com', 'delivered')->assertOk();

        $this->assertNotNull($key->refresh()->last_webhook_event_at);
    }

    public function test_an_unsigned_payload_is_rejected(): void
    {
        $key = $this->key();
        $this->receiver('someone@example.com');

        $this->call(
            'POST',
            '/api/v1/unione/webhook/' . $key->webhook_secret,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['events_by_user' => []], JSON_THROW_ON_ERROR),
        )->assertUnauthorized();

        $this->assertNull($key->refresh()->last_webhook_event_at, 'a rejected payload is not proof of life');
    }

    public function test_the_same_event_twice_changes_nothing_the_second_time(): void
    {
        // UniOne re-delivers until it gets a 200, so duplicates are expected.
        $key = $this->key();
        $receiver = $this->receiver('bounced@example.com');

        $this->postEvent($key, 'bounced@example.com', 'hard_bounced')->assertOk();
        $this->postEvent($key, 'bounced@example.com', 'hard_bounced')
            ->assertOk()
            ->assertJsonPath('duplicates', 1);

        $this->assertSame(1, (int) $receiver->refresh()->bounce_count, 'a re-delivery must not double-count');
    }
}
