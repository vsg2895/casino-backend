<?php

declare(strict_types=1);

namespace App\Support\UniOne;

/**
 * One UniOne API response, normalised.
 *
 * UniOne reports failure two different ways and both matter:
 *
 *   - an HTTP error (401, 403, 413, 429, 5xx) with `{status, message, code}`
 *   - an HTTP 200 whose body still lists addresses in `failed_emails`
 *
 * And one case that looks like the first but behaves like the second: when
 * EVERY recipient fails, the spec says "sending will not be carried out and API
 * error 204 will be returned" — an error body that nonetheless carries the
 * per-address reasons we must record. A caller that only read `failed_emails`
 * from 200s would throw away the bounce signal for an entire chunk and retry all
 * 500 dead addresses on the next run.
 *
 * This object is what makes both paths look the same to the caller.
 */
final class UniOneResponse
{
    /**
     * @param  array<int, string>     $emails        accepted addresses
     * @param  array<string, string>  $failedEmails  address => reason
     */
    private function __construct(
        public readonly bool $ok,
        public readonly int $httpStatus,
        public readonly ?string $jobId,
        public readonly array $emails,
        public readonly array $failedEmails,
        public readonly ?int $apiErrorCode,
        public readonly ?string $message,
        public readonly int $latencyMs,
        public readonly array $raw = [],
    ) {}

    /** UniOne's code for "every address in this request failed". */
    public const int ALL_RECIPIENTS_FAILED = 204;

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromHttp(int $status, array $body, int $latencyMs): self
    {
        $isSuccess = $status === 200 && ($body['status'] ?? null) === 'success';
        $code = isset($body['code']) ? (int) $body['code'] : null;

        /** @var array<string, string> $failed */
        $failed = is_array($body['failed_emails'] ?? null) ? $body['failed_emails'] : [];

        return new self(
            ok: $isSuccess,
            httpStatus: $status,
            jobId: isset($body['job_id']) ? (string) $body['job_id'] : null,
            emails: is_array($body['emails'] ?? null) ? array_values($body['emails']) : [],
            // Carried on BOTH paths — error 204 is the whole reason this class
            // exists.
            failedEmails: $failed,
            apiErrorCode: $code,
            message: isset($body['message']) ? (string) $body['message'] : null,
            latencyMs: $latencyMs,
            raw: $body,
        );
    }

    public static function transportFailure(string $message, int $latencyMs): self
    {
        return new self(false, 0, null, [], [], null, $message, $latencyMs);
    }

    /**
     * Whether a retry could plausibly succeed.
     *
     * 429 and 5xx only. A 400/401/403/413 is a defect in the request or the
     * credentials — retrying it burns attempts and, for 413, would send the same
     * oversized body again.
     */
    public function isRetryable(): bool
    {
        return $this->httpStatus === 429
            || ($this->httpStatus >= 500 && $this->httpStatus <= 599)
            // A transport failure (timeout, DNS, connection reset) never reached
            // UniOne, so nothing was sent and a retry is safe.
            || $this->httpStatus === 0;
    }

    /** Every address failed — an error body that still carries bounce signal. */
    public function allRecipientsFailed(): bool
    {
        return ! $this->ok && $this->apiErrorCode === self::ALL_RECIPIENTS_FAILED;
    }

    /**
     * Whether this response tells us anything about individual addresses.
     *
     * True for a success with failures AND for the all-failed error.
     */
    public function carriesRecipientOutcomes(): bool
    {
        return $this->ok || $this->allRecipientsFailed();
    }
}
