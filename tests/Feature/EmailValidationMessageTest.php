<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use App\Support\Validation\ValidationMessage;
use App\Support\Validation\ValidationReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * What a rejected visitor is actually told.
 *
 * The gate's behaviour is covered by EmailValidationSubscribeTest; this is only
 * about the sentence that comes back, because that sentence is the entire
 * difference between a person correcting a typo and a person giving up.
 */
class EmailValidationMessageTest extends TestCase
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

    /**
     * A SendGrid response with every check dialled individually, so each rule in
     * the decider can be provoked on its own.
     *
     * @param array<string, bool> $checks
     */
    private function fake(string $verdict, float $score, array $checks = []): void
    {
        $checks = [
            'has_valid_address_syntax'        => true,
            'has_mx_or_a_record'              => true,
            'is_suspected_disposable_address' => false,
            'is_suspected_role_address'       => false,
            'has_known_bounces'               => false,
            ...$checks,
        ];

        Http::fake(['api.sendgrid.com/*' => Http::response([
            'result' => [
                'email'   => 'someone@example.com',
                'verdict' => $verdict,
                'score'   => $score,
                'checks'  => [
                    'domain' => [
                        'has_valid_address_syntax'        => $checks['has_valid_address_syntax'],
                        'has_mx_or_a_record'              => $checks['has_mx_or_a_record'],
                        'is_suspected_disposable_address' => $checks['is_suspected_disposable_address'],
                    ],
                    'local_part' => ['is_suspected_role_address' => $checks['is_suspected_role_address']],
                    'additional' => [
                        'has_known_bounces'    => $checks['has_known_bounces'],
                        'has_suspected_bounces' => false,
                    ],
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

    /**
     * The guard against a new decider rule shipping with no wording.
     *
     * A rule that rejects people while ValidationMessage has nothing to say about
     * it falls back to the generic line — which is exactly the state this whole
     * change set out to fix, and it would happen silently.
     */
    public function test_every_rejecting_reason_has_wording_of_its_own(): void
    {
        foreach (ValidationReason::REJECTING as $reason) {
            $this->assertTrue(
                ValidationMessage::hasMessageFor($reason),
                "No visitor-facing message is defined for the reason '{$reason}'.",
            );

            $this->assertNotSame(
                ValidationMessage::FALLBACK,
                ValidationMessage::forReason($reason),
                "The reason '{$reason}' still falls back to the generic message.",
            );
        }
    }

    public function test_an_unknown_reason_falls_back_instead_of_failing(): void
    {
        $this->assertSame(ValidationMessage::FALLBACK, ValidationMessage::forReason(null));
        $this->assertSame(ValidationMessage::FALLBACK, ValidationMessage::forReason('nonexistent_rule'));
    }

    /**
     * Fail-open reasons must never reach a visitor: nothing was judged, so
     * nothing can be claimed about the address.
     */
    public function test_fail_open_reasons_have_no_wording(): void
    {
        foreach ([ValidationReason::MISSING_KEY, ValidationReason::TRANSPORT_ERROR,
            ValidationReason::QUOTA_EXHAUSTED, ValidationReason::DISABLED,
            ValidationReason::EMAIL_COOLDOWN, ValidationReason::PENDING_RESEND] as $reason) {
            $this->assertFalse(ValidationMessage::hasMessageFor($reason));
        }
    }

    // ── the message that comes back over HTTP ────────────────────────────────

    public function test_an_invalid_address_is_told_the_address_does_not_exist(): void
    {
        [$site, $key] = $this->site();
        $this->fake('Invalid', 0.02);

        $response = $this->subscribe($site, $key)->assertStatus(422);

        $expected = ValidationMessage::forReason(ValidationReason::INVALID_VERDICT);

        $this->assertSame($expected, $response->json('message'));
        // Both, because the form reads errors.email first and falls back to
        // message — they must not disagree.
        $this->assertSame($expected, $response->json('errors.email.0'));
        $this->assertStringContainsString("doesn't exist", $expected);
    }

    public function test_a_role_address_is_told_to_use_a_personal_one(): void
    {
        [$site, $key] = $this->site();
        config(['services.sendgrid_validation.reject_on_role_address' => true]);
        $this->fake('Valid', 0.95, ['is_suspected_role_address' => true]);

        $response = $this->subscribe($site, $key, 'info@example.com')->assertStatus(422);

        $this->assertSame(
            ValidationMessage::forReason(ValidationReason::ROLE_ADDRESS),
            $response->json('message'),
        );
        $this->assertStringContainsString('personal email address', $response->json('message'));
    }

    public function test_a_disposable_address_is_told_to_use_a_permanent_one(): void
    {
        [$site, $key] = $this->site();
        config(['services.sendgrid_validation.reject_on_disposable' => true]);
        $this->fake('Valid', 0.95, ['is_suspected_disposable_address' => true]);

        $response = $this->subscribe($site, $key)->assertStatus(422);

        $this->assertSame(
            ValidationMessage::forReason(ValidationReason::DISPOSABLE),
            $response->json('message'),
        );
        $this->assertStringContainsString('permanent', $response->json('message'));
    }

    public function test_a_domain_that_cannot_receive_mail_is_told_so(): void
    {
        [$site, $key] = $this->site();
        $this->fake('Risky', 0.60, ['has_mx_or_a_record' => false]);

        $response = $this->subscribe($site, $key)->assertStatus(422);

        $this->assertSame(
            ValidationMessage::forReason(ValidationReason::NO_MX_RECORD),
            $response->json('message'),
        );
    }

    /**
     * The messages are more specific than they were; they still must not carry
     * the verdict, the score, the checks or the machine reason code.
     */
    public function test_the_response_leaks_no_sendgrid_internals(): void
    {
        [$site, $key] = $this->site();
        $this->fake('Invalid', 0.02);

        $response = $this->subscribe($site, $key)->assertStatus(422);
        $body = $response->getContent();

        foreach (['Invalid', '0.02', 'verdict', 'score', 'checks', 'has_mx_or_a_record',
            ValidationReason::INVALID_VERDICT] as $internal) {
            $this->assertStringNotContainsString(
                $internal,
                $body,
                "The 422 body exposes '{$internal}' to the visitor.",
            );
        }

        $response->assertJsonMissingPath('verdict')
            ->assertJsonMissingPath('score')
            ->assertJsonMissingPath('reason');
    }
}
