<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * The outcome of ONE attempt to take a slot in a sliding send window.
 *
 * `allowed` already means the slot was RECORDED — there is no separate "commit"
 * step, because a decision that had to be confirmed later could be taken twice
 * by two workers between the check and the confirmation.
 *
 * `used` is the occupancy of the window AFTER this attempt, which is what the
 * operational log reports ("Rate window: 7/10"). `waitSeconds` is only
 * meaningful when the attempt was refused: it is how long until the OLDEST
 * recorded send leaves the window, i.e. the earliest moment a slot exists.
 */
final readonly class RateLimitDecision
{
    private function __construct(
        public bool $allowed,
        public int $used,
        public int $limit,
        public float $waitSeconds,
    ) {}

    public static function allowed(int $used, int $limit): self
    {
        return new self(true, $used, $limit, 0.0);
    }

    public static function refused(int $used, int $limit, float $waitSeconds): self
    {
        return new self(false, $used, $limit, max($waitSeconds, 0.0));
    }

    /** "7/10", for the log line. */
    public function occupancy(): string
    {
        return "{$this->used}/{$this->limit}";
    }
}
