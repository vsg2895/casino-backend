<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Support\Validation\ValidationDecision;
use App\Support\Validation\ValidationReason;

/**
 * Turns a SendGrid result into a decision. The ONLY place the rules live.
 *
 * Pure: no HTTP, no database, no cache, no clock. It takes the parsed `result`
 * array and returns a {@see ValidationDecision}. That is what makes every branch
 * unit-testable without faking a network, and it is why the controller and the
 * views contain no rule logic at all — they cannot, because they never see the
 * checks.
 *
 * EVERY threshold is read from config at decide() time, so switching to
 * ALLOWED_VERDICTS=Valid,Risky or moving MIN_SCORE is a .env change and a config
 * cache clear — no deploy. That is the whole point: the right values are not
 * knowable up front, they come from reading a month of the log.
 *
 * ORDER MATTERS and is fixed:
 *   1. hard rejects  — the address cannot receive mail at all
 *   2. allow         — every positive condition holds
 *   3. soft reject   — everything else, with the first failing rule named
 */
class EmailValidationDecider
{
    /**
     * @param  array<string, mixed>  $result  SendGrid's `result` object.
     */
    public function decide(array $result): ValidationDecision
    {
        $verdict = is_string($result['verdict'] ?? null) ? $result['verdict'] : null;
        $score = isset($result['score']) ? (float) $result['score'] : null;
        $checks = is_array($result['checks'] ?? null) ? $result['checks'] : [];

        $syntax = $this->check($checks, 'domain', 'has_valid_address_syntax');
        $mx = $this->check($checks, 'domain', 'has_mx_or_a_record');
        $disposable = $this->check($checks, 'domain', 'is_suspected_disposable_address');
        $role = $this->check($checks, 'local_part', 'is_suspected_role_address');
        $knownBounces = $this->check($checks, 'additional', 'has_known_bounces');

        // ── 1. HARD REJECT ───────────────────────────────────────────────────
        //
        // Syntax is tested FIRST and short-circuits, because SendGrid auto-fails
        // every other check when the syntax is bad. Reporting "no MX record" for
        // an address that is not an address would name a rule that never really
        // ran, and would poison the reason-code histogram this log exists for.
        if ($syntax === false) {
            return ValidationDecision::hardReject(ValidationReason::BAD_SYNTAX);
        }

        if ($verdict === 'Invalid') {
            return ValidationDecision::hardReject(ValidationReason::INVALID_VERDICT);
        }

        if ($mx === false) {
            return ValidationDecision::hardReject(ValidationReason::NO_MX_RECORD);
        }

        // ── 2. ALLOW — every condition must hold ─────────────────────────────
        if (! $this->verdictAllowed($verdict)) {
            return ValidationDecision::softReject(ValidationReason::VERDICT_NOT_ALLOWED);
        }

        if ($score !== null && $score < $this->minScore()) {
            return ValidationDecision::softReject(ValidationReason::LOW_SCORE);
        }

        // Required to be TRUE by the allow rule — a null (SendGrid did not say)
        // is not a positive, so it cannot satisfy it.
        if ($mx !== true) {
            return ValidationDecision::softReject(ValidationReason::NO_MX_RECORD);
        }

        if ($knownBounces === true && $this->flag('reject_on_known_bounces')) {
            return ValidationDecision::softReject(ValidationReason::KNOWN_BOUNCES);
        }

        // The two configurable extras, evaluated after the mandatory rules so
        // their reason codes only appear when they are genuinely the cause.
        if ($disposable === true && $this->flag('reject_on_disposable')) {
            return ValidationDecision::softReject(ValidationReason::DISPOSABLE);
        }

        if ($role === true && $this->flag('reject_on_role_address')) {
            return ValidationDecision::softReject(ValidationReason::ROLE_ADDRESS);
        }

        return ValidationDecision::allowed();
    }

    /**
     * Is this verdict on the configured allow list? Case-insensitive: the env
     * value is typed by a human, and "valid" must not silently reject everyone.
     */
    private function verdictAllowed(?string $verdict): bool
    {
        if ($verdict === null) {
            return false;
        }

        $allowed = array_map(
            static fn (string $v): string => mb_strtolower(trim($v)),
            (array) config('services.sendgrid_validation.allowed_verdicts', []),
        );

        return in_array(mb_strtolower($verdict), $allowed, true);
    }

    private function minScore(): float
    {
        return (float) config('services.sendgrid_validation.min_score', 0.7);
    }

    private function flag(string $key): bool
    {
        return (bool) config('services.sendgrid_validation.' . $key, false);
    }

    /**
     * One check, or null when SendGrid did not report it.
     *
     * Tri-state on purpose: "false" and "absent" are different, and treating a
     * missing check as false would hard-reject every address the moment SendGrid
     * renames a field.
     *
     * @param  array<string, mixed>  $checks
     */
    private function check(array $checks, string $group, string $name): ?bool
    {
        $value = $checks[$group][$name] ?? null;

        return is_bool($value) ? $value : null;
    }
}
