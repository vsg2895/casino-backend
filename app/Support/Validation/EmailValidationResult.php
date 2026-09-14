<?php

declare(strict_types=1);

namespace App\Support\Validation;

/**
 * The outcome of one validation attempt, in a shape the caller can branch on
 * without knowing anything about SendGrid.
 *
 * THREE STATES, and the distinction between the last two is the whole safety
 * property of this feature:
 *
 *   verdict reached   -> $verdict is Valid|Risky|Invalid, decide against config
 *   no result         -> $verdict is null, FAIL OPEN
 *
 * "No result" covers a missing key, a 4xx/5xx, a timeout, a connection error,
 * exhausted quota and a malformed body. None of them is evidence about the
 * address, so none of them may ever be read as "not allowed".
 */
final readonly class EmailValidationResult
{
    /**
     * @param  array<string, mixed>|null  $checks
     */
    private function __construct(
        public ?string $verdict,
        public ?float $score,
        public ?array $checks,
        public ?string $suggestion,
        public bool $wasCached,
        public bool $wasSkipped,
        public ?string $skipReason,
        public ?int $httpStatus,
        public ?string $errorMessage,
        public ?int $latencyMs,
    ) {}

    /**
     * @param  array<string, mixed>|null  $checks
     */
    public static function verdict(
        string $verdict,
        ?float $score,
        ?array $checks,
        ?string $suggestion,
        ?int $httpStatus = 200,
        ?int $latencyMs = null,
        bool $wasCached = false,
    ): self {
        return new self($verdict, $score, $checks, $suggestion, $wasCached, false, null, $httpStatus, null, $latencyMs);
    }

    /** Nothing was asked of SendGrid — disabled, no key, quota gone, cooldown. */
    public static function skipped(string $reason): self
    {
        return new self(null, null, null, null, false, true, $reason, null, null, null);
    }

    /** SendGrid was asked and could not answer usefully. */
    public static function failed(string $reason, ?int $httpStatus = null, ?int $latencyMs = null): self
    {
        return new self(null, null, null, null, false, false, null, $httpStatus, $reason, $latencyMs);
    }

    /** True only when SendGrid actually returned a verdict. */
    public function hasVerdict(): bool
    {
        return $this->verdict !== null;
    }

    /**
     * Did this cost a credit?
     *
     * A cache hit and a skip never do. A FAILED call is billed conservatively as
     * "not a credit" only when it never reached SendGrid; anything that got an
     * HTTP status back may well have been counted upstream, which is why the
     * admin also surfaces SendGrid's own reported balance rather than trusting
     * this number alone.
     */
    public function consumedCredit(): bool
    {
        return ! $this->wasCached && ! $this->wasSkipped;
    }

    /**
     * The shape the decider consumes — SendGrid's own `result` object.
     *
     * Rebuilt rather than carried around raw so the decider has one documented
     * input, and so a cached verdict (which never had a full payload) decides
     * identically to a fresh one.
     *
     * @return array<string, mixed>
     */
    public function toResultArray(): array
    {
        return [
            'verdict'    => $this->verdict,
            'score'      => $this->score,
            'checks'     => $this->checks ?? [],
            'suggestion' => $this->suggestion,
        ];
    }

    /** @return array<string, mixed> */
    public function toLogAttributes(): array
    {
        return [
            'verdict'       => $this->verdict,
            'score'         => $this->score,
            'checks'        => $this->checks,
            'suggestion'    => $this->suggestion,
            'was_cached'    => $this->wasCached,
            'was_skipped'   => $this->wasSkipped,
            'skip_reason'   => $this->skipReason,
            'http_status'   => $this->httpStatus,
            'error_message' => $this->errorMessage,
            'latency_ms'    => $this->latencyMs,
        ];
    }

    /** @return array<string, mixed> */
    public function toCacheArray(): array
    {
        return [
            'verdict'    => $this->verdict,
            'score'      => $this->score,
            'checks'     => $this->checks,
            'suggestion' => $this->suggestion,
        ];
    }
}
