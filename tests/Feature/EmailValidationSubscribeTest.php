<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessNewsletterSubscription;
use App\Jobs\SendNewsletterWelcomeEmail;
use App\Models\EmailValidationLog;
use App\Models\Newsletter;
use App\Models\Site;
use App\Support\Validation\ValidationOutcome;
use App\Support\Validation\ValidationReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The gate in front of the verification email.
 *
 * The assertion that matters in almost every test here is whether
 * SendNewsletterWelcomeEmail — the job that actually sends the verify email —
 * was dispatched. That is the whole feature: a rejected address must not receive
 * one, and NOTHING ELSE may stop one being sent.
 *
 * ProcessNewsletterSubscription is the only caller of that job, and the public
 * subscribe controller is the only caller of ProcessNewsletterSubscription, so
 * asserting on the outer job is equivalent and lets the tests stay at the
 * endpoint boundary.
 */
class EmailValidationSubscribeTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sendgrid_validation.enabled'          => true,
            'services.sendgrid_validation.key'              => 'SG.test-key',
            'services.sendgrid_validation.allowed_verdicts' => ['Valid'],
            'services.sendgrid_validation.monthly_quota'    => 2500,
        ]);

        Cache::flush();
    }

    /** @return array{0: Site, 1: string} */
    private function site(): array
    {
        return $this->siteWithKey(['newsletter_emails_enabled' => true]);
    }

    private function fakeVerdict(string $verdict, float $score = 0.95): void
    {
        Http::fake(['api.sendgrid.com/*' => Http::response([
            'result' => [
                'email'   => 'someone@example.com',
                'verdict' => $verdict,
                'score'   => $score,
                'checks'  => [
                    'domain'     => ['has_valid_address_syntax' => true, 'has_mx_or_a_record' => true, 'is_suspected_disposable_address' => false],
                    'local_part' => ['is_suspected_role_address' => false],
                    'additional' => ['has_known_bounces' => false, 'has_suspected_bounces' => false],
                ],
            ],
        ], 200)]);
    }

    private function subscribe(Site $site, string $key, string $email = 'someone@example.com')
    {
        return $this->postJson(
            $this->publicBase($site) . '/newsletter',
            ['email' => $email],
            $this->siteHeaders($key),
        );
    }

    // ── the allowed path ─────────────────────────────────────────────────────

    public function test_a_valid_address_is_subscribed_and_the_verify_email_is_dispatched(): void
    {
        Queue::fake();
        [$site, $key] = $this->site();
        $this->fakeVerdict('Valid');

        $this->subscribe($site, $key)->assertStatus(202);

        Queue::assertPushed(ProcessNewsletterSubscription::class);

        $log = EmailValidationLog::firstOrFail();
        $this->assertSame(ValidationOutcome::Allowed->value, $log->outcome);
        $this->assertSame('Valid', $log->verdict);
        $this->assertFalse($log->was_cached);
        $this->assertSame('subscribe_' . $site->slug, $log->source);
    }

    public function test_the_verdict_is_stamped_on_the_subscriber_row(): void
    {
        [$site, $key] = $this->site();
        $this->fakeVerdict('Valid', 0.87);

        // Not faking the queue: the job must run so the row is written.
        $this->subscribe($site, $key)->assertStatus(202);

        $subscriber = Newsletter::where('email', 'someone@example.com')->firstOrFail();
        $this->assertSame('Valid', $subscriber->validation_verdict);
        $this->assertEqualsWithDelta(0.87, (float) $subscriber->validation_score, 0.0001);
        $this->assertNotNull($subscriber->validated_at);
    }

    // ── the blocked path ─────────────────────────────────────────────────────

    public function test_an_invalid_address_creates_no_subscriber_and_sends_no_verify_email(): void
    {
        Queue::fake();
        [$site, $key] = $this->site();
        $this->fakeVerdict('Invalid', 0.02);

        $this->subscribe($site, $key)->assertStatus(422);

        // THE assertion: nothing was queued, so nothing can create a subscriber
        // or send a verification email.
        Queue::assertNotPushed(ProcessNewsletterSubscription::class);
        Queue::assertNotPushed(SendNewsletterWelcomeEmail::class);

        $this->assertDatabaseCount('newsletters', 0);

        $log = EmailValidationLog::firstOrFail();
        // Invalid is a HARD reject: the address cannot receive mail at all.
        $this->assertSame(ValidationOutcome::HardRejected->value, $log->outcome);
        $this->assertSame(ValidationReason::INVALID_VERDICT, $log->reason_code);
        $this->assertSame('Invalid', $log->verdict);
    }

    public function test_the_blocked_response_leaks_no_sendgrid_internals(): void
    {
        [$site, $key] = $this->site();
        $this->fakeVerdict('Invalid', 0.02);

        $body = $this->subscribe($site, $key)->assertStatus(422)->getContent();

        foreach (['Invalid', 'verdict', 'score', '0.02', 'sendgrid', 'SendGrid'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "response leaked '{$leak}'");
        }
    }

    public function test_risky_is_rejected_by_default(): void
    {
        Queue::fake();
        [$site, $key] = $this->site();
        // Score deliberately ABOVE the 0.7 threshold, so the rejection is
        // attributable to the verdict rule and not to a low score.
        $this->fakeVerdict('Risky', 0.88);

        $this->subscribe($site, $key)->assertStatus(422);
        Queue::assertNotPushed(ProcessNewsletterSubscription::class);

        $log = EmailValidationLog::firstOrFail();
        $this->assertSame(ValidationOutcome::SoftRejected->value, $log->outcome);
        $this->assertSame(ValidationReason::VERDICT_NOT_ALLOWED, $log->reason_code);
    }

    public function test_risky_is_allowed_when_the_env_widens_the_list(): void
    {
        Queue::fake();
        [$site, $key] = $this->site();
        config(['services.sendgrid_validation.allowed_verdicts' => ['Valid', 'Risky']]);
        // A SEPARATE test: calling Http::fake() twice does not replace a stub
        // for the same pattern, so a second call in one test proves nothing.
        $this->fakeVerdict('Risky', 0.88);

        $this->subscribe($site, $key)->assertStatus(202);
        Queue::assertPushed(ProcessNewsletterSubscription::class);
    }

    // ── fail open: every one of these MUST still send ────────────────────────

    #[DataProvider('failureModes')]
    public function test_failures_fail_open_and_still_dispatch_the_verify_email(callable $fake, string $label): void
    {
        Queue::fake();
        [$site, $key] = $this->site();
        $fake();

        $this->subscribe($site, $key)->assertStatus(202);

        // assertPushed's second argument is a callback, not a message — the
        // label is asserted on the log row below instead.
        Queue::assertPushed(ProcessNewsletterSubscription::class);

        $log = EmailValidationLog::firstOrFail();
        $this->assertSame(ValidationOutcome::FailedOpen->value, $log->outcome, $label);
        $this->assertNull($log->verdict, $label);
    }

    /** @return array<string, array{0: callable, 1: string}> */
    public static function failureModes(): array
    {
        return [
            '401 bad key'     => [fn () => Http::fake(['api.sendgrid.com/*' => Http::response([], 401)]), '401'],
            '403 wrong scope' => [fn () => Http::fake(['api.sendgrid.com/*' => Http::response([], 403)]), '403'],
            '429 rate limit'  => [fn () => Http::fake(['api.sendgrid.com/*' => Http::response([], 429)]), '429'],
            '500 upstream'    => [fn () => Http::fake(['api.sendgrid.com/*' => Http::response([], 500)]), '500'],
            'timeout'         => [fn () => Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out')), 'timeout'],
            'malformed body'  => [fn () => Http::fake(['api.sendgrid.com/*' => Http::response(['nope' => true], 200)]), 'malformed'],
        ];
    }

    public function test_a_missing_key_fails_open_without_calling_sendgrid(): void
    {
        Queue::fake();
        Http::fake();
        [$site, $key] = $this->site();
        config(['services.sendgrid_validation.key' => '']);

        $this->subscribe($site, $key)->assertStatus(202);

        Queue::assertPushed(ProcessNewsletterSubscription::class);
        Http::assertNothingSent();

        $log = EmailValidationLog::firstOrFail();
        $this->assertSame(ValidationOutcome::FailedOpen->value, $log->outcome);
        $this->assertSame('missing_key', $log->reason_code);
    }

    public function test_quota_exhaustion_fails_open_and_spends_nothing(): void
    {
        Queue::fake();
        Http::fake();
        [$site, $key] = $this->site();
        config(['services.sendgrid_validation.monthly_quota' => 1]);

        // One billed call already this month.
        EmailValidationLog::create([
            'site_id' => $site->id, 'email' => 'prior@example.com', 'source' => 'subscribe_x',
            'outcome' => ValidationOutcome::Allowed->value, 'verdict' => 'Valid',
            'was_cached' => false,
            'quota_month' => EmailValidationLog::currentQuotaMonth(),
        ]);

        $this->subscribe($site, $key)->assertStatus(202);

        Queue::assertPushed(ProcessNewsletterSubscription::class);
        Http::assertNothingSent();

        $log = EmailValidationLog::where('email', 'someone@example.com')->firstOrFail();
        $this->assertSame('quota_exhausted', $log->reason_code);
        $this->assertSame(ValidationOutcome::FailedOpen->value, $log->outcome);
    }

    // ── cost control ─────────────────────────────────────────────────────────

    public function test_a_repeat_address_is_served_from_cache_and_spends_no_credit(): void
    {
        [$site, $key] = $this->site();
        $this->fakeVerdict('Valid');

        $this->subscribe($site, $key)->assertStatus(202);
        // A second, different site so the pending-resend short circuit does not
        // fire and the CACHE is what is being proven.
        [$other, $otherKey] = $this->siteWithKey(['newsletter_emails_enabled' => true]);
        $this->subscribe($other, $otherKey)->assertStatus(202);

        Http::assertSentCount(1);

        $second = EmailValidationLog::where('site_id', $other->id)->firstOrFail();
        $this->assertTrue($second->was_cached);
        $this->assertSame('Valid', $second->verdict);
        $this->assertSame(1, EmailValidationLog::query()->billed()->count());
    }

    /**
     * A resend is VALIDATED, not waved through.
     *
     * This test used to assert the opposite — that an address which already
     * existed as an unverified subscriber skipped validation entirely, on the
     * reasoning that it "was judged when it first arrived".
     *
     * That premise was false in three real cases, and one of them was
     * self-perpetuating: a first attempt that FAILED OPEN (SendGrid unreachable,
     * key missing, quota gone) still created the unverified row, and from then on
     * the short circuit skipped validation forever. An address SendGrid would
     * call Invalid sailed through the subscribe form while the admin panel's own
     * Validate Email tool hard-rejected it.
     *
     * The cost concern the short circuit existed for is handled properly by the
     * RESULT CACHE — 60 days, checked before the cooldown and the quota — which
     * returns the real verdict where the skip returned none.
     */
    public function test_a_resend_is_still_validated_and_still_costs_nothing(): void
    {
        [$site, $key] = $this->site();
        $this->fakeVerdict('Valid');

        $this->subscribe($site, $key)->assertStatus(202);
        Http::assertSentCount(1);

        // The row now exists unverified. Re-submitting is the resend path.
        $this->subscribe($site, $key)->assertStatus(202);

        // Still ONE upstream call: the second was served from the cache.
        Http::assertSentCount(1);

        $resend = EmailValidationLog::latest('id')->firstOrFail();
        // A real verdict, not a skip — this is the whole point of the change.
        $this->assertSame('Valid', $resend->verdict);
        $this->assertSame(ValidationOutcome::Allowed->value, $resend->outcome);
        $this->assertTrue((bool) $resend->was_cached);
        $this->assertSame(1, EmailValidationLog::query()->billed()->count());
    }

    /** An unverified row with no verdict must NOT grant a permanent free pass. */
    public function test_an_unjudged_pending_row_is_validated_on_the_next_attempt(): void
    {
        [$site, $key] = $this->site();

        // Exactly what a fail-open leaves behind: subscribed, unverified, never
        // actually judged.
        \App\Models\Newsletter::create([
            'site_id'  => $site->id,
            'email'    => 'someone@example.com',
            'verified' => false,
        ]);

        $this->fakeVerdict('Invalid');

        $this->subscribe($site, $key)->assertStatus(422);

        $log = EmailValidationLog::latest('id')->firstOrFail();
        $this->assertSame('Invalid', $log->verdict);
        $this->assertSame(ValidationOutcome::HardRejected->value, $log->outcome);
    }

    public function test_an_already_verified_address_is_rejected_before_any_credit(): void
    {
        Http::fake();
        [$site, $key] = $this->site();

        Newsletter::create(['site_id' => $site->id, 'email' => 'someone@example.com'])
            ->forceFill(['verified' => true])->save();

        $this->subscribe($site, $key)->assertStatus(422);

        Http::assertNothingSent();
        $this->assertDatabaseCount('email_validation_logs', 0);
    }

    // ── abuse protection ─────────────────────────────────────────────────────

    public function test_a_typo_suggestion_is_returned_to_the_form(): void
    {
        [$site, $key] = $this->site();
        Http::fake(['api.sendgrid.com/*' => Http::response(['result' => [
            'verdict' => 'Invalid', 'score' => 0.05,
            'checks' => [
                'domain' => ['has_valid_address_syntax' => true, 'has_mx_or_a_record' => true, 'is_suspected_disposable_address' => false],
                'local_part' => ['is_suspected_role_address' => false],
                'additional' => ['has_known_bounces' => false, 'has_suspected_bounces' => false],
            ],
            'suggestion' => 'gmail.com',
        ]], 200)]);

        $r = $this->subscribe($site, $key, 'person@gmial.com')->assertStatus(422);

        // The ONE validation detail a visitor is allowed to see.
        $r->assertJsonPath('suggestion', 'gmail.com');
        $r->assertJsonPath('suggested_email', 'person@gmail.com');
        // And still nothing else.
        foreach (['Invalid', 'verdict', 'score', 'reason_code'] as $leak) {
            $this->assertStringNotContainsString($leak, $r->getContent());
        }

        $this->assertSame('gmail.com', EmailValidationLog::firstOrFail()->suggestion);
    }

    public function test_the_corrected_address_gets_its_own_validation_call(): void
    {
        [$site, $key] = $this->site();
        $this->fakeVerdict('Valid');

        // The cache is keyed on the ADDRESS, so a corrected one cannot be served
        // the typo's verdict — the whole point of offering the correction.
        $this->subscribe($site, $key, 'person@gmial.com')->assertStatus(202);
        $this->subscribe($site, $key, 'person@gmail.com')->assertStatus(202);

        Http::assertSentCount(2);
    }

    public function test_exactly_one_log_row_is_written_per_attempt(): void
    {
        [$site, $key] = $this->site();
        $this->fakeVerdict('Valid');

        $this->subscribe($site, $key, 'a@example.com')->assertStatus(202);
        $this->subscribe($site, $key, 'b@example.com')->assertStatus(202);

        $this->assertSame(2, EmailValidationLog::count());
    }

    public function test_the_per_minute_throttle_admits_exactly_five(): void
    {
        Http::fake();
        [$site, $key] = $this->site();

        // Regression guard. Two stacked `throttle:` middlewares derive the same
        // cache key from the route signature, so they SHARE a counter and every
        // request costs two hits — a "5 per minute" limit that really admitted
        // three. One named limiter returning two Limits with distinct by() keys
        // is what makes the windows independent.
        $accepted = 0;

        foreach (range(1, 8) as $i) {
            $status = $this->postJson(
                $this->publicBase($site) . '/newsletter',
                ['email' => "burst{$i}@example.com"],
                $this->siteHeaders($key),
            )->getStatusCode();

            if ($status !== 429) {
                $accepted++;
            }
        }

        $this->assertSame(5, $accepted, 'the subscribe endpoint must admit exactly 5 requests per minute');
    }

    public function test_the_honeypot_blocks_before_any_credit_is_spent(): void
    {
        Queue::fake();
        Http::fake();
        [$site, $key] = $this->site();

        $this->postJson(
            $this->publicBase($site) . '/newsletter',
            ['email' => 'bot@example.com', 'website' => 'http://spam.example'],
            $this->siteHeaders($key),
        )->assertStatus(422);

        Http::assertNothingSent();
        Queue::assertNotPushed(ProcessNewsletterSubscription::class);
        $this->assertDatabaseCount('email_validation_logs', 0);
    }
}
