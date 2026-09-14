<?php

declare(strict_types=1);

namespace App\Support\Validation;

/**
 * The decider's answer: an outcome plus the rule that produced it.
 */
final readonly class ValidationDecision
{
    public function __construct(
        public ValidationOutcome $outcome,
        /** Null only when nothing was rejected and no rule needed naming. */
        public ?string $reason = null,
    ) {}

    public static function allowed(): self
    {
        return new self(ValidationOutcome::Allowed);
    }

    public static function hardReject(string $reason): self
    {
        return new self(ValidationOutcome::HardRejected, $reason);
    }

    public static function softReject(string $reason): self
    {
        return new self(ValidationOutcome::SoftRejected, $reason);
    }

    public static function failOpen(string $reason): self
    {
        return new self(ValidationOutcome::FailedOpen, $reason);
    }

    public function permitsSubscribe(): bool
    {
        return $this->outcome->permitsSubscribe();
    }
}
