<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Models\EmailValidationLog;
use App\Models\Site;
use App\Support\Validation\EmailValidationResult;
use App\Support\Validation\ValidationDecision;
use App\Support\Validation\ValidationReason;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides whether a subscribe attempt may proceed, and records the attempt.
 *
 * Holds the FLOW and the persistence; holds NO rules. Every threshold and flag
 * lives in {@see EmailValidationDecider}, which is pure — so the rules can be
 * unit-tested without a network and changed from .env without a deploy.
 *
 * Four outcomes, and exactly one log row is written for every one of them:
 *
 *   allowed        subscriber created, verify email sent
 *   hard_rejected  the address cannot receive mail — refused
 *   soft_rejected  probably deliverable but failed policy — refused
 *   failed_open    no usable result — proceeds exactly as before this feature
 *
 * There is no fifth branch. Anything that is not an explicit judgement by
 * SendGrid resolves to failed_open: a transport failure is never evidence about
 * an address, and six live sites must not stop collecting subscribers because
 * SendGrid is having a bad afternoon.
 */
class SubscribeValidationGate
{
    private ?EmailValidationResult $lastResult = null;

    private ?ValidationDecision $lastDecision = null;

    public function __construct(
        private readonly SendGridEmailValidationService $sendgrid,
        private readonly EmailValidationDecider $decider,
    ) {}

    public function decide(Site $site, string $email): ValidationDecision
    {
        $this->lastResult = null;

        /*
         * ── REMOVED: the "pending resend" short circuit ───────────────────────
         *
         * This used to skip validation entirely whenever the address already
         * existed as an unverified subscriber, on the reasoning that it "was
         * judged when it first arrived, so re-judging it would spend a credit to
         * re-answer a settled question".
         *
         * That reasoning was wrong, and it was wrong in a way that got worse
         * over time, because the premise is false in three real cases:
         *
         *   1. The row predates this feature and was never judged at all.
         *   2. The row was created by an Excel import, which does not validate.
         *   3. THE TRAP: the first attempt FAILED OPEN — SendGrid was
         *      unreachable, the key was missing, the quota was gone — which
         *      created the unverified row without a verdict. Every later attempt
         *      then matched this short circuit and skipped validation too. The
         *      address could never be validated again, by design, forever.
         *
         * Case 3 is self-perpetuating: fail-open creates the row, the row
         * suppresses validation, so the outage's effect is permanent instead of
         * lasting as long as the outage. An address SendGrid would reject as
         * Invalid kept sailing through the subscribe form while the admin panel's
         * own Validate Email tool called the same address hard-rejected.
         *
         * Nothing is lost by removing it. Avoiding a repeat charge is what the
         * RESULT CACHE in SendGridEmailValidationService already does — 60 days,
         * checked before the cooldown and before the quota — and it does it
         * better, because a cache hit returns the real verdict where this
         * returned no verdict at all and fell open. The resend itself still
         * works: ProcessNewsletterSubscription re-sends the verify email to any
         * existing unverified subscriber on its own.
         */
        $result = $this->sendgrid->validate($email, $site->slug);

        // ── FAIL OPEN ─────────────────────────────────────────────────────────
        if (! $result->hasVerdict()) {
            return $this->record(
                $site,
                $email,
                $result,
                ValidationDecision::failOpen($result->skipReason ?? ValidationReason::TRANSPORT_ERROR),
            );
        }

        $this->lastResult = $result;

        // The rules live in ONE place, and this is the call into it.
        $decision = $this->decider->decide($result->toResultArray());

        return $this->record($site, $email, $result, $decision);
    }

    /** The verdict reached this request, for stamping on the subscriber row. */
    public function lastResult(): ?EmailValidationResult
    {
        return $this->lastResult;
    }

    public function lastDecision(): ?ValidationDecision
    {
        return $this->lastDecision;
    }

    /**
     * Write the one audit row for this attempt, and return the decision.
     *
     * The six checks are written as REAL COLUMNS as well as into `raw_checks`:
     * every question the log exists to answer is a filter or an aggregate over
     * them, and JSON extraction cannot use an index.
     *
     * Wrapped: a logging failure must never turn into a failed subscribe. The
     * row is the audit trail; the visitor's subscription is the product.
     */
    private function record(
        Site $site,
        string $email,
        EmailValidationResult $result,
        ValidationDecision $decision,
    ): ValidationDecision {
        $this->lastDecision = $decision;
        $checks = $result->checks ?? [];

        try {
            EmailValidationLog::create([
                'site_id'     => $site->id,
                'email'       => $email,
                'source'      => 'subscribe_' . $site->slug,
                'outcome'     => $decision->outcome->value,
                'reason_code' => $decision->reason,
                'quota_month' => EmailValidationLog::currentQuotaMonth(),

                'verdict'    => $result->verdict,
                'score'      => $result->score,
                'suggestion' => $result->suggestion,

                'has_valid_address_syntax'        => $this->flag($checks, 'domain', 'has_valid_address_syntax'),
                'has_mx_or_a_record'              => $this->flag($checks, 'domain', 'has_mx_or_a_record'),
                'is_suspected_disposable_address' => $this->flag($checks, 'domain', 'is_suspected_disposable_address'),
                'is_suspected_role_address'       => $this->flag($checks, 'local_part', 'is_suspected_role_address'),
                'has_known_bounces'               => $this->flag($checks, 'additional', 'has_known_bounces'),
                'has_suspected_bounces'           => $this->flag($checks, 'additional', 'has_suspected_bounces'),

                // Kept whole so nothing is lost if SendGrid adds fields.
                'raw_checks'    => $result->checks,
                'was_cached'    => $result->wasCached,
                'http_status'   => $result->httpStatus,
                'error_message' => $result->errorMessage,
                'latency_ms'    => $result->latencyMs,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to write email validation log', [
                'site_id' => $site->id,
                'outcome' => $decision->outcome->value,
                'error'   => $e->getMessage(),
            ]);
        }

        return $decision;
    }

    /**
     * One check as a nullable bool.
     *
     * Tri-state: "false" and "absent" are different, and storing a missing check
     * as false would invent evidence.
     *
     * @param  array<string, mixed>  $checks
     */
    private function flag(array $checks, string $group, string $name): ?bool
    {
        $value = $checks[$group][$name] ?? null;

        return is_bool($value) ? $value : null;
    }
}
