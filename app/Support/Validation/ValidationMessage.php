<?php

declare(strict_types=1);

namespace App\Support\Validation;

/**
 * What a rejected visitor is told, per reason.
 *
 * ValidationReason names the RULE that fired; this names the SENTENCE the person
 * reads. They are separate on purpose — the reason codes are an API the admin log
 * and its filters depend on, and rewording a message must never mean rewriting a
 * stored code or breaking a saved filter.
 *
 * A deliberate change of position: the endpoint used to answer every rejection
 * with one generic line. Generic was the safer default — a distinct message per
 * rule tells a prober something about an address they typed, which is a small
 * oracle. But it was also unactionable. Somebody who fat-fingered "gmial.com" and
 * somebody using a throwaway inbox got the same shrug, and neither learned what
 * to do next, so both simply left.
 *
 * The line these messages hold is verdict, score, checks and every other
 * SendGrid internal: none of it appears here, and none of it is ever serialised
 * to the frontend. What a visitor gets is a category and an instruction — the
 * same thing a decent form has always told them.
 *
 * Written to be read by someone who does not know what an MX record is.
 */
final class ValidationMessage
{
    /**
     * The answer when the reason is unknown, or is one that should never have
     * reached a visitor. Every fail-open reason lands here if it somehow does,
     * which is correct: nothing was actually judged, so nothing can be claimed.
     */
    public const string FALLBACK = "This address can't be used. Please check it, or try another.";

    /** @var array<string, string> */
    private const array MESSAGES = [
        // ── hard rejects: the address genuinely cannot receive mail ──────────
        ValidationReason::BAD_SYNTAX => "That doesn't look like a complete email address. Please check it for typos — it should look like name@example.com.",

        ValidationReason::INVALID_VERDICT => "That email address doesn't exist. Please check it for a typo, or use another address.",

        ValidationReason::NO_MX_RECORD => "The part after the @ isn't set up to receive email. Please check the domain, or use another address.",

        // ── soft rejects: probably deliverable, but failed policy ────────────
        //
        // "We couldn't confirm" rather than "your address is bad": these two
        // fire on a judgement call, and some of the people who see them have a
        // perfectly working address on an unusual domain. Telling them their
        // address is broken would be a claim the check did not actually make.
        ValidationReason::LOW_SCORE => "We couldn't confirm that this address can receive email. Please double-check it, or try another one.",

        ValidationReason::VERDICT_NOT_ALLOWED => "We couldn't confirm that this address can receive email. Please double-check it, or try another one.",

        ValidationReason::KNOWN_BOUNCES => 'Email to this address has bounced before, so it cannot be added. Please use a different address.',

        ValidationReason::DISPOSABLE => 'This looks like a temporary or disposable address. Please use a permanent one — the confirmation link has to reach you.',

        ValidationReason::ROLE_ADDRESS => 'Shared addresses like info@ or admin@ cannot be used. Please use a personal email address.',
    ];

    /**
     * The sentence for a reason code.
     *
     * Never throws on an unknown code. A reason that has no message is a
     * copywriting gap, and a 500 in front of a visitor is a far worse answer to
     * it than a generic line.
     */
    public static function forReason(?string $reason): string
    {
        if ($reason === null) {
            return self::FALLBACK;
        }

        return self::MESSAGES[$reason] ?? self::FALLBACK;
    }

    /**
     * Whether a reason has wording of its own.
     *
     * Exists for the test that asserts every rejecting reason is covered, so a
     * new rule cannot be added to the decider and silently inherit FALLBACK.
     */
    public static function hasMessageFor(string $reason): bool
    {
        return isset(self::MESSAGES[$reason]);
    }
}
