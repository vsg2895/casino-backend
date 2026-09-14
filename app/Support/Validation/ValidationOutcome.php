<?php

declare(strict_types=1);

namespace App\Support\Validation;

/**
 * What the subscribe flow does with an address.
 *
 * HARD and SOFT rejection are separated deliberately, even though both refuse
 * the subscriber and both show the visitor the same generic message. They are
 * different *evidence*:
 *
 *   hard_rejected  the address cannot receive mail — bad syntax, no MX/A record,
 *                  or SendGrid says Invalid. Loosening the rules will never make
 *                  these deliverable.
 *   soft_rejected  the address probably works but failed policy — a Risky
 *                  verdict, a score under the threshold, a role address. These
 *                  are the rows worth re-reading in a month to decide whether
 *                  the policy is too strict.
 *
 * Collapsing them into one "blocked" would destroy exactly the distinction the
 * log exists to support.
 */
enum ValidationOutcome: string
{
    case Allowed = 'allowed';
    case HardRejected = 'hard_rejected';
    case SoftRejected = 'soft_rejected';
    /** No usable result: transport failure, no key, quota gone. Never a judgement. */
    case FailedOpen = 'failed_open';

    /** Does the subscriber get created and the verify email sent? */
    public function permitsSubscribe(): bool
    {
        return $this === self::Allowed || $this === self::FailedOpen;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
