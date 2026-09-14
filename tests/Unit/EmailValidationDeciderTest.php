<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Validation\EmailValidationDecider;
use App\Support\Validation\ValidationOutcome;
use App\Support\Validation\ValidationReason;
use Tests\TestCase;

/**
 * Every branch of the decision table.
 *
 * No HTTP, no database — the decider is pure, which is exactly why the rules
 * live there and not in the controller.
 */
class EmailValidationDeciderTest extends TestCase
{
    private EmailValidationDecider $decider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->decider = new EmailValidationDecider();

        config([
            'services.sendgrid_validation.allowed_verdicts'        => ['Valid'],
            'services.sendgrid_validation.min_score'               => 0.7,
            'services.sendgrid_validation.reject_on_known_bounces' => true,
            'services.sendgrid_validation.reject_on_disposable'    => true,
            'services.sendgrid_validation.reject_on_role_address'  => false,
        ]);
    }

    /** @param array<string, bool> $overrides */
    private function payload(string $verdict, float $score, array $overrides = []): array
    {
        return [
            'verdict' => $verdict,
            'score'   => $score,
            'checks'  => [
                'domain' => [
                    'has_valid_address_syntax'        => $overrides['syntax'] ?? true,
                    'has_mx_or_a_record'              => $overrides['mx'] ?? true,
                    'is_suspected_disposable_address' => $overrides['disposable'] ?? false,
                ],
                'local_part' => ['is_suspected_role_address' => $overrides['role'] ?? false],
                'additional' => [
                    'has_known_bounces'     => $overrides['known_bounces'] ?? false,
                    'has_suspected_bounces' => $overrides['suspected_bounces'] ?? false,
                ],
            ],
        ];
    }

    // ── 1. hard rejects ──────────────────────────────────────────────────────

    public function test_invalid_verdict_is_a_hard_reject(): void
    {
        $d = $this->decider->decide($this->payload('Invalid', 0.01));

        $this->assertSame(ValidationOutcome::HardRejected, $d->outcome);
        $this->assertSame(ValidationReason::INVALID_VERDICT, $d->reason);
        $this->assertFalse($d->permitsSubscribe());
    }

    public function test_bad_syntax_is_a_hard_reject(): void
    {
        $d = $this->decider->decide($this->payload('Invalid', 0.0, ['syntax' => false]));

        $this->assertSame(ValidationOutcome::HardRejected, $d->outcome);
        $this->assertSame(ValidationReason::BAD_SYNTAX, $d->reason);
    }

    public function test_bad_syntax_wins_over_every_other_check(): void
    {
        // SendGrid auto-fails every other check when syntax is bad, so naming
        // any of them would name a rule that never really ran and would poison
        // the reason-code histogram.
        $d = $this->decider->decide($this->payload('Invalid', 0.0, [
            'syntax' => false, 'mx' => false, 'disposable' => true, 'known_bounces' => true,
        ]));

        $this->assertSame(ValidationReason::BAD_SYNTAX, $d->reason);
    }

    public function test_a_missing_mx_record_is_a_hard_reject(): void
    {
        $d = $this->decider->decide($this->payload('Valid', 0.95, ['mx' => false]));

        $this->assertSame(ValidationOutcome::HardRejected, $d->outcome);
        $this->assertSame(ValidationReason::NO_MX_RECORD, $d->reason);
    }

    // ── 2. allow ─────────────────────────────────────────────────────────────

    public function test_a_clean_valid_address_is_allowed(): void
    {
        $d = $this->decider->decide($this->payload('Valid', 0.86474));

        $this->assertSame(ValidationOutcome::Allowed, $d->outcome);
        $this->assertNull($d->reason);
        $this->assertTrue($d->permitsSubscribe());
    }

    public function test_the_score_threshold_is_inclusive(): void
    {
        $this->assertSame(ValidationOutcome::Allowed, $this->decider->decide($this->payload('Valid', 0.7))->outcome);
        $this->assertSame(ValidationOutcome::SoftRejected, $this->decider->decide($this->payload('Valid', 0.6999))->outcome);
    }

    // ── 3. soft rejects ──────────────────────────────────────────────────────

    public function test_a_valid_verdict_below_the_threshold_is_a_soft_reject(): void
    {
        $d = $this->decider->decide($this->payload('Valid', 0.42));

        $this->assertSame(ValidationOutcome::SoftRejected, $d->outcome);
        $this->assertSame(ValidationReason::LOW_SCORE, $d->reason);
        $this->assertFalse($d->permitsSubscribe());
    }

    public function test_known_bounces_soft_reject_when_the_flag_is_on(): void
    {
        $d = $this->decider->decide($this->payload('Valid', 0.95, ['known_bounces' => true]));

        $this->assertSame(ValidationOutcome::SoftRejected, $d->outcome);
        $this->assertSame(ValidationReason::KNOWN_BOUNCES, $d->reason);
    }

    public function test_known_bounces_are_ignored_when_the_flag_is_off(): void
    {
        config(['services.sendgrid_validation.reject_on_known_bounces' => false]);

        $d = $this->decider->decide($this->payload('Valid', 0.95, ['known_bounces' => true]));

        $this->assertSame(ValidationOutcome::Allowed, $d->outcome);
    }

    public function test_risky_is_rejected_under_the_default_config(): void
    {
        $d = $this->decider->decide($this->payload('Risky', 0.9));

        $this->assertSame(ValidationOutcome::SoftRejected, $d->outcome);
        $this->assertSame(ValidationReason::VERDICT_NOT_ALLOWED, $d->reason);
    }

    public function test_risky_is_allowed_when_the_env_widens_the_list(): void
    {
        // The change this whole design exists to make possible: .env only.
        config(['services.sendgrid_validation.allowed_verdicts' => ['Valid', 'Risky']]);

        $d = $this->decider->decide($this->payload('Risky', 0.9));

        $this->assertSame(ValidationOutcome::Allowed, $d->outcome);
    }

    public function test_a_disposable_address_soft_rejects_by_default(): void
    {
        $d = $this->decider->decide($this->payload('Valid', 0.9, ['disposable' => true]));

        $this->assertSame(ValidationOutcome::SoftRejected, $d->outcome);
        $this->assertSame(ValidationReason::DISPOSABLE, $d->reason);
    }

    public function test_a_disposable_address_passes_when_the_flag_is_off(): void
    {
        config(['services.sendgrid_validation.reject_on_disposable' => false]);

        $this->assertSame(
            ValidationOutcome::Allowed,
            $this->decider->decide($this->payload('Valid', 0.9, ['disposable' => true]))->outcome,
        );
    }

    public function test_a_role_address_is_allowed_by_default(): void
    {
        // info@/support@/sales@ are frequently the real mailbox on a B2B-leaning
        // audience, so the default does NOT reject them.
        $this->assertSame(
            ValidationOutcome::Allowed,
            $this->decider->decide($this->payload('Valid', 0.9, ['role' => true]))->outcome,
        );
    }

    public function test_a_role_address_soft_rejects_when_the_flag_is_on(): void
    {
        config(['services.sendgrid_validation.reject_on_role_address' => true]);

        $d = $this->decider->decide($this->payload('Valid', 0.9, ['role' => true]));

        $this->assertSame(ValidationOutcome::SoftRejected, $d->outcome);
        $this->assertSame(ValidationReason::ROLE_ADDRESS, $d->reason);
    }

    // ── robustness ───────────────────────────────────────────────────────────

    public function test_a_missing_check_is_not_treated_as_false(): void
    {
        // If SendGrid renames a field, absent checks must not hard-reject every
        // address on the network.
        $d = $this->decider->decide(['verdict' => 'Valid', 'score' => 0.9, 'checks' => []]);

        $this->assertSame(ValidationOutcome::SoftRejected, $d->outcome);
        $this->assertSame(ValidationReason::NO_MX_RECORD, $d->reason);
        $this->assertNotSame(ValidationOutcome::HardRejected, $d->outcome);
    }

    public function test_an_unknown_verdict_is_never_allowed(): void
    {
        $d = $this->decider->decide($this->payload('Unknown', 0.99));

        $this->assertSame(ValidationOutcome::SoftRejected, $d->outcome);
        $this->assertSame(ValidationReason::VERDICT_NOT_ALLOWED, $d->reason);
    }
}
