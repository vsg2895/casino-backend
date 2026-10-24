<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The result of asking {@see WarmupRunService::start()} for a run.
 *
 * Returned instead of thrown: every refusal is an ordinary state the operator
 * has to be told about, and each carries the sentence they are told.
 */
final readonly class WarmupRunOutcome
{
    private function __construct(
        public string $message,
        public ?WarmupRunRefusal $refusal = null,
        public ?int $sendId = null,
        public int $recipients = 0,
    ) {}

    public static function queued(int $sendId, int $recipients, string $message): self
    {
        return new self(message: $message, sendId: $sendId, recipients: $recipients);
    }

    public static function refused(WarmupRunRefusal $refusal, string $message): self
    {
        return new self(message: $message, refusal: $refusal);
    }

    public function wasQueued(): bool
    {
        return $this->refusal === null;
    }
}
