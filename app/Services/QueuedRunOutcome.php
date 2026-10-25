<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The result of asking for a queued bulk run (warmup, bulk SMS).
 *
 * Returned instead of thrown: every refusal is an ordinary state the operator
 * has to be told about, and each carries the sentence they are told. `sendId`
 * is set only by runs that record a run row before dispatching.
 */
final readonly class QueuedRunOutcome
{
    private function __construct(
        public string $message,
        public ?QueuedRunRefusal $refusal = null,
        public ?int $sendId = null,
        public int $recipients = 0,
    ) {}

    public static function queued(int $recipients, string $message, ?int $sendId = null): self
    {
        return new self(message: $message, sendId: $sendId, recipients: $recipients);
    }

    public static function refused(QueuedRunRefusal $refusal, string $message): self
    {
        return new self(message: $message, refusal: $refusal);
    }

    public function wasQueued(): bool
    {
        return $this->refusal === null;
    }
}
