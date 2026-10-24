<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * The outcome of an admin "Send test" of a promotion template.
 *
 * Three shapes, because the operator acts on them differently: nothing was
 * attempted (no site, no usable transport — fix the configuration), the
 * transport refused the message (look at the provider), or it was accepted.
 */
final readonly class PromotionTestSendResult
{
    private function __construct(
        public bool $ok,
        public string $message,
        public bool $attempted,
    ) {}

    public static function sent(string $message): self
    {
        return new self(ok: true, message: $message, attempted: true);
    }

    /** Stopped before anything reached a transport. */
    public static function notAttempted(string $message): self
    {
        return new self(ok: false, message: $message, attempted: false);
    }

    /** Handed to the transport, which threw. */
    public static function failed(string $message): self
    {
        return new self(ok: false, message: $message, attempted: true);
    }
}
