<?php

declare(strict_types=1);

namespace App\Support\Validation;

/**
 * Machine-readable reason a decision was reached.
 *
 * Stored as a plain indexed column so "which rule is rejecting the most people"
 * is a GROUP BY rather than a text search, and so the admin can filter on it
 * without parsing a message.
 *
 * The strings are an API of sorts — the admin filter and any later analysis read
 * them — so they are named for the RULE that fired, not for the wording shown to
 * anyone.
 */
final class ValidationReason
{
    // ── hard rejects: the address cannot receive mail ────────────────────────
    public const string INVALID_VERDICT = 'invalid_verdict';
    public const string BAD_SYNTAX = 'bad_syntax';
    public const string NO_MX_RECORD = 'no_mx_record';

    // ── soft rejects: probably deliverable, failed policy ────────────────────
    public const string LOW_SCORE = 'low_score';
    public const string KNOWN_BOUNCES = 'known_bounces';
    public const string DISPOSABLE = 'disposable';
    public const string ROLE_ADDRESS = 'role_address';
    public const string VERDICT_NOT_ALLOWED = 'verdict_not_allowed';

    // ── fail open: no judgement was made ─────────────────────────────────────
    public const string MISSING_KEY = 'missing_key';
    public const string QUOTA_EXHAUSTED = 'quota_exhausted';
    public const string EMAIL_COOLDOWN = 'email_cooldown';
    public const string DISABLED = 'disabled';
    public const string PENDING_RESEND = 'pending_resend';
    public const string TRANSPORT_ERROR = 'transport_error';

    /**
     * @var list<string> The reasons that actually turn a visitor away.
     *
     * Everything else in ALL is a fail-open reason: no judgement was made, the
     * subscribe proceeded, and the person saw nothing. Kept beside the codes so
     * that adding a rule to the decider puts the question "what does this one
     * tell the visitor?" in front of whoever adds it — ValidationMessage is
     * asserted to cover every entry here.
     */
    public const array REJECTING = [
        self::INVALID_VERDICT, self::BAD_SYNTAX, self::NO_MX_RECORD,
        self::LOW_SCORE, self::KNOWN_BOUNCES, self::DISPOSABLE,
        self::ROLE_ADDRESS, self::VERDICT_NOT_ALLOWED,
    ];

    /** @var list<string> Reasons an operator can filter the log by. */
    public const array ALL = [
        self::INVALID_VERDICT, self::BAD_SYNTAX, self::NO_MX_RECORD,
        self::LOW_SCORE, self::KNOWN_BOUNCES, self::DISPOSABLE,
        self::ROLE_ADDRESS, self::VERDICT_NOT_ALLOWED,
        self::MISSING_KEY, self::QUOTA_EXHAUSTED, self::EMAIL_COOLDOWN,
        self::DISABLED, self::PENDING_RESEND, self::TRANSPORT_ERROR,
    ];
}
