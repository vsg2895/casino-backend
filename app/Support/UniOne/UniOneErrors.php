<?php

declare(strict_types=1);

namespace App\Support\UniOne;

/**
 * Turns a UniOne API error into something an operator can act on.
 *
 * ── Why this is a small, honest map rather than a big one ───────────────────
 *
 * UniOne's published spec references error codes but does not enumerate them.
 * Every entry below has been OBSERVED against the live API or is named in the
 * spec text — nothing here is guessed. An unknown code falls through to
 * UniOne's own message, which is always better than a wrong explanation.
 *
 * The distinction that matters to an operator is whether the failure is about
 * THE REQUEST (fix the message), THE ACCOUNT (fix the plan or the domain), or
 * THE MOMENT (wait and retry) — because only the last one resolves on its own.
 */
final class UniOneErrors
{
    /** Every recipient in the request was rejected; the send did not happen. */
    public const int ALL_RECIPIENTS_FAILED = 204;

    /** Free tier: recipients must be on a verified domain or a checked address. */
    public const int FREE_TIER_DOMAIN_RESTRICTION = 903;

    /**
     * Guidance per code, keyed by what the operator has to do about it.
     *
     * @var array<int, array{summary: string, action: string}>
     */
    private const GUIDANCE = [
        self::ALL_RECIPIENTS_FAILED => [
            'summary' => 'UniOne rejected every address in this batch, so nothing was sent.',
            'action'  => 'The per-address reasons are in the row detail below — they have already been applied to the list, so these addresses will not be tried again.',
        ],
        self::FREE_TIER_DOMAIN_RESTRICTION => [
            'summary' => 'Your UniOne plan is free_tier, which may only send to domains or addresses you have verified.',
            'action'  => 'Either send only to addresses on a verified domain (check the Domains tab in the UniOne section), add the specific recipient as a checked address in UniOne, or upgrade the plan. Nothing in this admin can lift the restriction — it is set on the UniOne account.',
        ],
    ];

    /**
     * A single sentence for the log row and the toast.
     *
     * Falls back to UniOne's own message rather than inventing one — an
     * explanation that is confidently wrong costs more than no explanation.
     */
    public static function explain(?int $code, ?string $message, int $httpStatus = 0): string
    {
        $raw = trim((string) $message);

        if ($code !== null && isset(self::GUIDANCE[$code])) {
            $g = self::GUIDANCE[$code];

            return $g['summary'] . ' ' . $g['action'];
        }

        // Tracking has its own dedicated hint because the brief asks for it and
        // the raw message never mentions the support step.
        if ($raw !== '' && stripos($raw, 'track') !== false) {
            return $raw . ' — turning link or open tracking off requires UniOne support to enable that option on your account.';
        }

        return match (true) {
            $httpStatus === 401 || $httpStatus === 403 => $raw !== ''
                ? $raw
                : 'UniOne refused the credentials. Re-verify the key in the UniOne section.',
            $httpStatus === 413 => 'The message body exceeded UniOne\'s 10 MB limit. Shorten the HTML or move images to hosted URLs.',
            $httpStatus === 429 => 'UniOne rate-limited the account. Every worker has been paused for this key; the run will resume on its own.',
            $httpStatus >= 500  => 'UniOne had a server error. This is retried automatically.',
            $raw !== ''         => $raw,
            default             => 'UniOne rejected the request without a message.',
        };
    }

    /** Whether the operator has to change something before retrying. */
    public static function needsOperatorAction(?int $code, int $httpStatus): bool
    {
        return $code === self::FREE_TIER_DOMAIN_RESTRICTION
            || in_array($httpStatus, [400, 401, 403, 413], true);
    }
}
