<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\UniOne\SendUniOneChunkJob;
use App\Models\UniOne\UniOneApiKey;
use App\Models\UniOne\UniOneReceiver;
use App\Models\UniOne\UniOneSend;
use App\Models\UniOne\UniOneSendChunk;
use App\Services\UniOne\UniOneKeyService;
use App\Support\UniOne\UniOneWebhookVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The UniOne integration, against Http::fake for every documented shape.
 *
 * The cases that matter most are the ones a naive implementation gets wrong:
 * the error-204 body that still carries failed_emails, a retry of a committed
 * chunk, and a webhook replay.
 */
class UniOneIntegrationTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private const KEY = 'test-unione-key';

    /**
     * Every run and every test send renders viglinksi's promotion template, so
     * the site has to exist before a chunk job can run. Created once per test,
     * with the default template row materialised on first use.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \App\Models\Site::factory()->create([
            'slug'   => \App\Services\UniOne\UniOneTemplateService::SITE_SLUG,
            'name'   => 'Viglinksi',
            'domain' => 'viglinksi.test',
        ]);
    }

    private function key(array $attrs = []): UniOneApiKey
    {
        $key = UniOneApiKey::query()->create([
            'name'     => 'Test key',
            'api_key'  => self::KEY,
            'key_type' => UniOneApiKey::TYPE_USER,
            'region'   => UniOneApiKey::REGION_EU1,
            'is_active' => true,
            ...$attrs,
        ]);

        return $key->refresh();
    }

    private function receiver(string $email, array $attrs = []): UniOneReceiver
    {
        return UniOneReceiver::query()->create(['email' => $email, ...$attrs]);
    }

    // ── key storage ──────────────────────────────────────────────────────────

    public function test_the_api_key_is_encrypted_at_rest_and_never_serialised(): void
    {
        $key = $this->key();

        $raw = DB::table('unione_api_keys')->where('id', $key->id)->value('api_key');

        $this->assertNotSame(self::KEY, $raw, 'the column must not hold plaintext');
        $this->assertStringNotContainsString(self::KEY, (string) $raw);
        // Decrypts transparently for use.
        $this->assertSame(self::KEY, $key->api_key);
        // And never leaves the process.
        $this->assertArrayNotHasKey('api_key', $key->toArray());
        $this->assertArrayNotHasKey('webhook_secret', $key->toArray());
    }

    public function test_the_masked_key_shows_only_the_last_four_characters(): void
    {
        $masked = $this->key(['api_key' => 'abcdefghijklmnop'])->maskedKey();

        $this->assertStringEndsWith('mnop', $masked);
        $this->assertStringNotContainsString('abcdefghij', $masked);
    }

    public function test_a_webhook_token_is_generated_automatically(): void
    {
        $this->assertNotEmpty($this->key()->webhook_secret);
    }

    // ── exactly one default ──────────────────────────────────────────────────

    public function test_promoting_a_key_demotes_every_other_default(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'user_id' => 1])]);

        $service = app(UniOneKeyService::class);

        $first = $this->key(['name' => 'first']);
        $second = $this->key(['name' => 'second']);

        $service->verify($first);
        $service->verify($second);

        $service->makeDefault($first->refresh());
        $service->makeDefault($second->refresh());

        $this->assertSame(1, UniOneApiKey::query()->where('is_default', true)->count());
        $this->assertTrue($second->refresh()->is_default);
        $this->assertFalse($first->refresh()->is_default);
    }

    public function test_an_unverified_key_cannot_become_the_default(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(UniOneKeyService::class)->makeDefault($this->key());
    }

    public function test_the_key_cache_is_busted_on_write(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'user_id' => 1])]);

        $service = app(UniOneKeyService::class);
        $key = $this->key();
        $service->verify($key);
        $service->makeDefault($key->refresh());

        $this->assertSame($key->id, $service->default()?->id);

        // A rotated key must not keep serving from cache.
        $key->forceFill(['api_key' => 'rotated-key'])->save();
        $service->forget($key->id);

        $this->assertSame('rotated-key', $service->default()?->api_key);
    }

    // ── sending ──────────────────────────────────────────────────────────────

    private function dispatchRun(int $count, array $attrs = []): UniOneSend
    {
        return app(\App\Services\UniOne\UniOneSendService::class)->dispatchRun(
            $this->key($attrs),
            [
                'subject' => 'Hello', 'from_email' => 'promo@viglinksi.com',
                'count' => $count, 'cooldown_days' => null,
            ],
            null,
        );
    }

    public function test_a_run_chunks_at_exactly_500_recipients(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        for ($i = 1; $i <= 1001; $i++) {
            $this->receiver("r{$i}@example.test");
        }

        $send = $this->dispatchRun(1001);

        // 1001 → 500 + 500 + 1. The boundary is where an off-by-one would show.
        $this->assertSame(3, $send->chunk_count);
        $this->assertSame([500, 500, 1], $send->chunks()->pluck('recipient_count')->all());
        \Illuminate\Support\Facades\Queue::assertPushed(SendUniOneChunkJob::class, 3);
    }

    public function test_exactly_500_recipients_is_one_chunk_not_two(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        for ($i = 1; $i <= 500; $i++) {
            $this->receiver("r{$i}@example.test");
        }

        $this->assertSame(1, $this->dispatchRun(500)->chunk_count);
    }

    public function test_the_idempotence_key_is_deterministic_and_within_64_chars(): void
    {
        $a = UniOneSendChunk::idempotenceKeyFor(42, 3);
        $b = UniOneSendChunk::idempotenceKeyFor(42, 3);

        // A retry MUST reproduce it, or the key does nothing at all.
        $this->assertSame($a, $b);
        $this->assertLessThanOrEqual(64, strlen($a));
        $this->assertNotSame($a, UniOneSendChunk::idempotenceKeyFor(42, 4));
    }

    public function test_a_successful_send_stamps_the_recipients_and_commits_the_chunk(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'success', 'job_id' => 'job-1',
            'emails' => ['a@example.test', 'b@example.test'], 'failed_emails' => [],
        ])]);

        $a = $this->receiver('a@example.test');
        $b = $this->receiver('b@example.test');
        $send = $this->dispatchRunSync(2);

        $this->assertSame(2, $send->refresh()->accepted_count);
        $this->assertNotNull($a->refresh()->last_sent_at);
        $this->assertSame(1, $a->refresh()->send_count);
        $this->assertSame('job-1', $send->chunks()->first()->job_id);
        $this->assertNotNull($send->chunks()->first()->committed_at);
    }

    public function test_failed_emails_are_mapped_to_receiver_statuses(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'success', 'job_id' => 'job-2',
            'emails' => ['good@example.test'],
            'failed_emails' => [
                'invalid@example.test'   => 'invalid',
                'perm@example.test'      => 'permanent_unavailable',
                'unsub@example.test'     => 'unsubscribed',
                'spam@example.test'      => 'complained',
                'blocked@example.test'   => 'blocked',
                'temp@example.test'      => 'temporary_unavailable',
            ],
        ])]);

        foreach (['good', 'invalid', 'perm', 'unsub', 'spam', 'blocked', 'temp'] as $name) {
            $this->receiver("{$name}@example.test");
        }

        $this->dispatchRunSync(10);

        $status = fn (string $e): string => UniOneReceiver::query()->where('email', $e)->value('status');

        $this->assertSame(UniOneReceiver::STATUS_ACTIVE, $status('good@example.test'));
        $this->assertSame(UniOneReceiver::STATUS_BOUNCED, $status('invalid@example.test'));
        $this->assertSame(UniOneReceiver::STATUS_BOUNCED, $status('perm@example.test'));
        $this->assertSame(UniOneReceiver::STATUS_UNSUBSCRIBED, $status('unsub@example.test'));
        $this->assertSame(UniOneReceiver::STATUS_COMPLAINED, $status('spam@example.test'));
        $this->assertSame(UniOneReceiver::STATUS_UNSUBSCRIBED, $status('blocked@example.test'));

        // temporary_unavailable keeps the row ACTIVE and holds it for 3 days —
        // a soft failure describes the moment, not the mailbox.
        $temp = UniOneReceiver::query()->where('email', 'temp@example.test')->first();
        $this->assertSame(UniOneReceiver::STATUS_ACTIVE, $temp->status);
        $this->assertTrue($temp->retry_after->isFuture());
        $this->assertFalse(UniOneReceiver::query()->sendable()->where('email', 'temp@example.test')->exists());
    }

    public function test_api_error_204_still_yields_its_failed_emails(): void
    {
        // THE case a naive implementation drops: every address failed, so UniOne
        // answers with an ERROR body — which nonetheless carries the reasons.
        Http::fake(['*' => Http::response([
            'status' => 'error', 'code' => 204, 'message' => 'All recipients failed',
            'failed_emails' => ['dead@example.test' => 'permanent_unavailable'],
        ], 400)]);

        $this->receiver('dead@example.test');
        $send = $this->dispatchRunSync(1);

        $this->assertSame(
            UniOneReceiver::STATUS_BOUNCED,
            UniOneReceiver::query()->where('email', 'dead@example.test')->value('status'),
            'the bounce signal from a 204 must not be discarded',
        );
        $this->assertNotNull($send->chunks()->first()->committed_at);
        $this->assertSame(204, $send->chunks()->first()->api_error_code);
    }

    public function test_a_retry_of_a_committed_chunk_sends_nothing(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'success', 'job_id' => 'job-3',
            'emails' => ['once@example.test'], 'failed_emails' => [],
        ])]);

        $receiver = $this->receiver('once@example.test');
        $send = $this->dispatchRunSync(1);

        $firstSentAt = $receiver->refresh()->last_sent_at;
        $this->assertSame(1, $receiver->refresh()->send_count);

        // Replay the exact job. The idempotence key expired 19 minutes ago in
        // real time; `committed_at` is what actually stops this.
        (new SendUniOneChunkJob($send->id, 0, [$receiver->id]))->handle(app(\App\Services\UniOne\UniOneOutcomeService::class));

        $this->assertSame(1, $receiver->refresh()->send_count, 'a retry must not double-count');
        $this->assertEquals($firstSentAt, $receiver->refresh()->last_sent_at);
        Http::assertSentCount(1);
    }

    public function test_a_400_is_never_retried(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'code' => 3003, 'message' => 'Bad request'], 400)]);

        $this->receiver('x@example.test');
        $send = $this->dispatchRunSync(1);

        $chunk = $send->chunks()->first();
        $this->assertSame(UniOneSendChunk::STATUS_FAILED, $chunk->status);
        $this->assertSame(1, $chunk->attempts, 'a 400 describes the request — retrying it is pointless');
        Http::assertSentCount(1);
    }

    public function test_a_429_starts_a_shared_cooldown_for_every_worker(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'slow down'], 429)]);

        $key = $this->key();
        $client = new \App\Services\UniOne\UniOneClient($key);
        $client->ping();

        // The brake lives in the SHARED cache, so a second worker sees it too.
        $this->assertTrue((new \App\Services\UniOne\UniOneClient($key->refresh()))->isCoolingDown());
    }

    /**
     * Dispatch a run and let it complete.
     *
     * The test queue connection is `sync`, so `dispatch()` executes the chunk
     * jobs inline and there is nothing further to drive. An earlier version of
     * this helper re-ran each job by hand and every chunk recorded TWO attempts
     * — the helper was double-sending, not the job.
     */
    private function dispatchRunSync(int $count): UniOneSend
    {
        return $this->dispatchRun($count)->refresh();
    }

    public function test_a_free_tier_refusal_is_explained_not_just_echoed(): void
    {
        // The exact 403/903 the live account returned when a gmail.com address
        // was included on the free tier.
        Http::fake(['*' => Http::response([
            'status'  => 'error',
            'code'    => 903,
            'message' => "Error ID:6A8DBF54. On the 'free_tier' tariff it is allowed to send letters only to the 'checked' domains or 'checked' emails. The request contains external domain(s) 'gmail.com'.",
        ], 403)]);

        $this->receiver('someone@gmail.com');
        $send = $this->dispatchRunSync(1);
        $chunk = $send->chunks()->first();

        // Never retried — a tariff restriction does not resolve by trying again.
        $this->assertSame(1, $chunk->attempts);
        $this->assertSame(UniOneSendChunk::STATUS_FAILED, $chunk->status);
        $this->assertNull($chunk->committed_at);
        $this->assertSame(903, $chunk->api_error_code);

        // Nobody is marked as contacted when nothing was sent.
        $this->assertNull(UniOneReceiver::query()->where('email', 'someone@gmail.com')->value('last_sent_at'));
        $this->assertSame(UniOneSend::STATUS_FAILED, $send->refresh()->status);

        // The stored error TELLS the operator what to change — UniOne's own
        // wording never says the fix is on the account rather than in here.
        $this->assertStringContainsString('free_tier', $chunk->error);
        $this->assertStringContainsString('verified', $chunk->error);
        // And the run carries it, so the list explains itself.
        $this->assertNotNull($send->refresh()->error);
    }

    public function test_an_unknown_error_code_falls_back_to_unio_ne_s_own_message(): void
    {
        // An explanation that is confidently wrong costs more than none.
        $this->assertSame(
            'Some brand new message',
            \App\Support\UniOne\UniOneErrors::explain(99999, 'Some brand new message', 400),
        );
    }

    // ── webhooks ─────────────────────────────────────────────────────────────

    /** Build a payload signed the way UniOne signs one. */
    private function signedPayload(string $apiKey, array $events): array
    {
        $template = json_encode([
            'auth' => '__AUTH__',
            'events_by_user' => [['user_id' => 1, 'events' => $events]],
        ], JSON_UNESCAPED_SLASHES);

        // The documented rule: MD5 of the body with the API KEY in the auth slot.
        $withKey = str_replace('__AUTH__', $apiKey, $template);
        $hash = md5($withKey);

        return [str_replace('__AUTH__', $hash, $template), $hash];
    }

    private function emailEvent(string $email, string $status, string $time = '2026-01-01 10:00:00'): array
    {
        return [
            'event_name' => 'transactional_email_status',
            'event_data' => ['job_id' => 'job-9', 'email' => $email, 'status' => $status, 'event_time' => $time],
        ];
    }

    public function test_a_webhook_with_a_bad_token_is_rejected_with_401(): void
    {
        $this->key();
        [$body] = $this->signedPayload(self::KEY, []);

        $this->call('POST', '/api/v1/unione/webhook/not-a-real-token', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertUnauthorized();
    }

    public function test_a_webhook_with_a_bad_signature_is_rejected_with_401(): void
    {
        $key = $this->key();
        // Signed with the WRONG key — token is right, hash is not.
        [$body] = $this->signedPayload('some-other-key', [$this->emailEvent('a@example.test', 'delivered')]);

        $this->call('POST', "/api/v1/unione/webhook/{$key->webhook_secret}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertUnauthorized();
    }

    public function test_a_genuine_webhook_updates_the_receiver(): void
    {
        $key = $this->key();
        $receiver = $this->receiver('bounce@example.test');

        [$body] = $this->signedPayload(self::KEY, [$this->emailEvent('bounce@example.test', 'hard_bounced')]);

        $this->call('POST', "/api/v1/unione/webhook/{$key->webhook_secret}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk()->assertJsonPath('ingested', 1);

        // Routed through the SAME mapping as failed_emails: hard_bounced lands
        // where permanent_unavailable does.
        $this->assertSame(UniOneReceiver::STATUS_BOUNCED, $receiver->refresh()->status);
        $this->assertSame(1, $receiver->refresh()->bounce_count);
    }

    public function test_the_same_event_delivered_twice_does_not_double_count(): void
    {
        $key = $this->key();
        $receiver = $this->receiver('dup@example.test');

        [$body] = $this->signedPayload(self::KEY, [$this->emailEvent('dup@example.test', 'spam')]);

        $post = fn () => $this->call('POST', "/api/v1/unione/webhook/{$key->webhook_secret}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $post()->assertOk()->assertJsonPath('ingested', 1);
        // UniOne re-delivers until it gets a 200 — so a replay must be a 200 AND
        // must change nothing.
        $post()->assertOk()->assertJsonPath('duplicates', 1);

        $this->assertSame(1, $receiver->refresh()->complaint_count, 'a replay must not double-count');
        $this->assertSame(1, \App\Models\UniOne\UniOneWebhookEvent::query()->count());
    }

    public function test_a_soft_bounce_holds_the_address_without_unsubscribing_it(): void
    {
        $key = $this->key();
        $receiver = $this->receiver('soft@example.test');

        [$body] = $this->signedPayload(self::KEY, [$this->emailEvent('soft@example.test', 'soft_bounced')]);

        $this->call('POST', "/api/v1/unione/webhook/{$key->webhook_secret}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $fresh = $receiver->refresh();
        $this->assertSame(UniOneReceiver::STATUS_ACTIVE, $fresh->status);
        $this->assertTrue($fresh->retry_after->isFuture());
    }

    public function test_a_delivered_event_changes_no_eligibility(): void
    {
        $key = $this->key();
        $receiver = $this->receiver('ok@example.test');

        [$body] = $this->signedPayload(self::KEY, [$this->emailEvent('ok@example.test', 'delivered')]);

        $this->call('POST', "/api/v1/unione/webhook/{$key->webhook_secret}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertSame(UniOneReceiver::STATUS_ACTIVE, $receiver->refresh()->status);
        $this->assertSame('delivered', $receiver->refresh()->last_status);
    }

    public function test_the_verifier_rejects_a_tampered_body(): void
    {
        [$body] = $this->signedPayload(self::KEY, [$this->emailEvent('a@example.test', 'delivered')]);

        $this->assertTrue(UniOneWebhookVerifier::verify($body, self::KEY));
        // One character changed anywhere invalidates the hash.
        $this->assertFalse(UniOneWebhookVerifier::verify(str_replace('user_id":1', 'user_id":2', $body), self::KEY));
    }

    // ── the template ─────────────────────────────────────────────────────────

    public function test_every_run_renders_the_viglinksi_promotion_template(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'success', 'job_id' => 'job-t',
            'emails' => ['t@example.test'], 'failed_emails' => [],
        ])]);

        $this->receiver('t@example.test', ['name' => 'Tamar']);
        $send = $this->dispatchRunSync(1);

        // Stored as a marker, never as markup — the HTML is rendered per recipient.
        $this->assertStringStartsWith(\App\Services\UniOne\UniOneSendService::TEMPLATE_MARKER, $send->html_body);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            $body = $request->data()['message'] ?? [];
            $html = $body['recipients'][0]['substitutions']['body_html'] ?? '';

            return ($body['template_engine'] ?? null) === 'simple'
                && ($body['body']['html'] ?? null) === '{{body_html}}'
                && str_contains($html, 'Tamar')
                && ! str_contains($html, 'unione-placeholder');
        });
    }

    public function test_a_test_send_renders_the_same_template_as_a_run(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'job_id' => 'job-x', 'emails' => ['me@example.test'], 'failed_emails' => []])]);
        $key = $this->key();

        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/unione/sends/test', [
            'unione_api_key_id' => $key->id,
            'email' => 'me@example.test', 'from_email' => 'promo@viglinksi.test',
        ])->assertOk();

        $expected = app(\App\Services\UniOne\UniOneTemplateService::class)->renderFor('me@example.test', null);
        $subject = app(\App\Services\UniOne\UniOneTemplateService::class)->subjectFor();

        Http::assertSent(fn (\Illuminate\Http\Client\Request $r): bool => ($r->data()['message']['body']['html'] ?? null) === $expected
            && ($r->data()['message']['subject'] ?? null) === $subject);
    }

    public function test_the_template_preview_returns_the_rendered_html_and_subject(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/unione/sends/template-preview')
            ->assertOk()
            ->assertJsonPath('data.site', 'viglinksi')
            ->assertJsonPath('data.subject', app(\App\Services\UniOne\UniOneTemplateService::class)->subjectFor());

        $html = $this->getJson('/api/v1/admin/unione/sends/template-preview')->json('data.html');
        $this->assertStringContainsString('<', $html);
        $this->assertStringNotContainsString('unione-placeholder', $html);
    }

    public function test_the_cooldown_is_accepted_in_days(): void
    {
        $this->actingAsAdmin();
        $this->receiver('a@example.test')->forceFill(['last_sent_at' => now()->subDay()])->save();
        $this->receiver('b@example.test')->forceFill(['last_sent_at' => now()->subDays(5)])->save();

        $this->postJson('/api/v1/admin/unione/sends/preview', ['count' => 10, 'cooldown_days' => 2])
            ->assertOk()->assertJsonPath('data.eligible', 1);
        $this->postJson('/api/v1/admin/unione/sends/preview', ['count' => 10, 'cooldown_days' => 0])
            ->assertOk()->assertJsonPath('data.eligible', 2);
    }

    // ── the sendable scope ───────────────────────────────────────────────────

    public function test_only_active_receivers_are_sendable(): void
    {
        $ok = $this->receiver('ok@example.test');
        $this->receiver('bounced@example.test', ['status' => UniOneReceiver::STATUS_BOUNCED]);
        $this->receiver('unsub@example.test', ['status' => UniOneReceiver::STATUS_UNSUBSCRIBED]);
        $this->receiver('held@example.test')->forceFill(['retry_after' => now()->addDay()])->save();

        $sendable = UniOneReceiver::query()->sendable()->pluck('email')->all();

        $this->assertSame([$ok->email], $sendable);
    }

    public function test_an_address_without_consent_is_still_sendable(): void
    {
        /*
         * This asserts a DELIBERATE RELAXATION, not an accident.
         *
         * Consent used to be NOT NULL and required by scopeSendable(). Both
         * were removed at the operator's request so the import could take a file
         * and nothing else. The columns survive as optional metadata.
         *
         * The cost is recorded in UniOneReceiver::scopeSendable: UniOne's terms
         * still require documented consent, and this application no longer
         * enforces it. If that rule is ever reinstated, this test is the one to
         * invert.
         */
        $receiver = UniOneReceiver::query()->create(['email' => 'noconsent@example.test']);

        $this->assertNull($receiver->consent_at);
        $this->assertNull($receiver->consent_source);
        $this->assertTrue(
            UniOneReceiver::query()->sendable()->whereKey($receiver->id)->exists(),
            'consent is no longer a send gate',
        );
    }

    public function test_rotation_puts_never_contacted_first_then_least_recent(): void
    {
        $this->receiver('old@example.test')->forceFill(['last_sent_at' => now()->subDays(10)])->save();
        $this->receiver('recent@example.test')->forceFill(['last_sent_at' => now()->subDay()])->save();
        $never = $this->receiver('never@example.test');

        $order = UniOneReceiver::query()->sendable()->rotation()->pluck('email')->all();

        $this->assertSame(['never@example.test', 'old@example.test', 'recent@example.test'], $order);
        $this->assertNull($never->last_sent_at);
    }

    public function test_the_cooldown_excludes_recently_contacted_but_never_the_untouched(): void
    {
        $this->receiver('never@example.test');
        // Days, like Warmup: "2" skips anyone contacted in the last two days.
        $this->receiver('inside@example.test')->forceFill(['last_sent_at' => now()->subDay()])->save();
        $this->receiver('outside@example.test')->forceFill(['last_sent_at' => now()->subDays(3)])->save();

        $eligible = UniOneReceiver::query()->sendable()->outsideCooldown(2)->pluck('email')->all();

        sort($eligible);
        $this->assertSame(['never@example.test', 'outside@example.test'], $eligible);
    }
}
