<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\PhoneSmsHistory;

/**
 * The outcome of ONE send attempt.
 *
 * Returned instead of throwing, because a bulk run must not treat "this one
 * number was rejected" as an exception: the batch continues, and every outcome —
 * good or bad — becomes a history row. Exceptions are reserved for a broken
 * credential or an unreachable API, which are conditions of the whole run.
 */
final readonly class SmsSendResult
{
    private function __construct(
        public bool $ok,
        public ?string $messageSid = null,
        public ?int $errorCode = null,
        public ?string $error = null,
    ) {}

    public static function sent(?string $messageSid): self
    {
        return new self(ok: true, messageSid: $messageSid);
    }

    public static function failed(?int $errorCode, string $error): self
    {
        return new self(ok: false, errorCode: $errorCode, error: $error);
    }

    public function status(): string
    {
        return $this->ok ? PhoneSmsHistory::STATUS_SENT : PhoneSmsHistory::STATUS_FAILED;
    }

    /**
     * Whether the recipient has opted out (Twilio 21610).
     *
     * The one failure that must change stored state rather than just be recorded:
     * the number is flagged so later runs skip it. Continuing to message a STOP'd
     * number is a compliance problem, not merely a wasted send.
     */
    public function isOptOut(): bool
    {
        return $this->errorCode === PhoneSmsHistory::ERROR_OPTED_OUT;
    }
}
