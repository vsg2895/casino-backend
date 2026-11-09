<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Services\Mail\WarmupRateLimiter;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * How a BULK batch puts messages on the wire — the way a campaign platform such
 * as Brevo does, and the way warmup already did:
 *
 *  1. THROTTLED PER ACCOUNT. Every attempt first takes a slot in its sending
 *     account's rolling window: at most WARMUP_EMAILS_PER_MINUTE in any 60
 *     seconds, evenly spaced, counted across every worker
 *     ({@see WarmupRateLimiter}). A skipped address takes no slot.
 *  2. SOFT FAILURES RETRIED ONCE. A 4xx reply or a dropped connection
 *     reconnects the transport and tries again after a fresh slot — a soft
 *     bounce. A 5xx, or a second failure, is final and goes back to the caller.
 *  3. A TIME BUDGET, NOT A KILLED JOB. Throttled, a batch takes minutes, longer
 *     than its job may run. {@see budgetSpent()} says when to stop, and the
 *     caller hands the addresses it did not reach to a new job of the same
 *     batch. Every invocation attempts at least one send, so a batch always
 *     advances.
 *
 * One instance per batch invocation. Transactional mail (verify, forum,
 * tests) never comes through here: it must arrive while someone is waiting.
 */
final class PacedDelivery
{
    /** Held back from the job timeout on top of one window: the last flush and the re-queue. */
    private const int BUDGET_MARGIN_SECONDS = 20;

    private readonly float $budgetSeconds;

    private readonly int $startedAtMs;

    private int $attempts = 0;

    /**
     * @param  string  $label  Prefix for the log lines, e.g. "[Schedule]".
     */
    public function __construct(
        private readonly WarmupRateLimiter $limiter,
        int $jobTimeoutSeconds,
        private readonly string $label,
    ) {
        $reserve = $this->limiter->windowSeconds() + self::BUDGET_MARGIN_SECONDS;
        // At least one second, so a nonsensically small timeout still sends the
        // one address every invocation is guaranteed to attempt.
        $this->budgetSeconds = max((float) $jobTimeoutSeconds - $reserve, 1.0);
        // The limiter's clock, so faking time in a test moves both together.
        $this->startedAtMs = (int) Carbon::now()->getPreciseTimestamp(3);
    }

    /** A pacer on the account's own window. */
    public static function forAccount(string $account, int $jobTimeoutSeconds, string $label): self
    {
        return new self(app(WarmupRateLimiter::class)->forAccount($account), $jobTimeoutSeconds, $label);
    }

    /**
     * Whether this invocation should stop and re-queue the rest. Never true
     * before the first attempt, so a batch can never hand itself back forever.
     */
    public function budgetSpent(): bool
    {
        if ($this->attempts === 0) {
            return false;
        }

        $elapsed = ((int) Carbon::now()->getPreciseTimestamp(3) - $this->startedAtMs) / 1000;

        return $elapsed >= $this->budgetSeconds;
    }

    /**
     * Send one message under the account's rate, retrying once if the failure
     * is temporary. Throws the final failure for the caller to record.
     */
    public function send(Mailer $mailer, string $email, Mailable $mailable): void
    {
        $this->acquire();
        $this->attempts++;

        try {
            $mailer->to($email)->send($mailable);

            return;
        } catch (Throwable $e) {
            if (! self::isTemporaryFailure($e)) {
                throw $e;
            }

            Log::info($this->label . ' Temporary send failure; retrying once.', [
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }

        self::resetTransport($mailer);
        $this->acquire();
        $mailer->to($email)->send($mailable);
    }

    /** Messages attempted by this invocation. */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /** "8 per 60s", for the batch summary line. */
    public function rate(): string
    {
        return $this->limiter->max() . ' per ' . $this->limiter->windowSeconds() . 's';
    }

    private function acquire(): void
    {
        $this->limiter->acquire(function ($refused, float $wait): void {
            // Debug: at the configured rate a run waits every few seconds, and
            // the per-batch summary is what an operator reads.
            Log::debug($this->label . ' Rate limit reached. Waiting ' . round($wait, 1) . ' seconds.', [
                'rate_window' => $refused->occupancy(),
                'account_key' => $this->limiter->key(),
            ]);
        });
    }

    /**
     * Whether a send failure is worth one retry: a 4xx SMTP reply or a
     * connection-level error. 5xx replies are permanent and never retried.
     */
    public static function isTemporaryFailure(Throwable $e): bool
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        if (is_int($code) && $code >= 400 && $code < 500) {
            return true;
        }

        if (preg_match('/\b4[0-9]{2}[ -]|code "4[0-9]{2}"/', $message) === 1 && preg_match('/\b5[0-9]{2}[ -]|code "5[0-9]{2}"/', $message) !== 1) {
            return true;
        }

        return preg_match('/connection (could not be established|reset|refused|timed out)|timed out|broken pipe|unable to (connect|write)/i', $message) === 1;
    }

    /** Drop the transport session so the retry opens a fresh one. Best effort. */
    public static function resetTransport(Mailer $mailer): void
    {
        try {
            if (method_exists($mailer, 'getSymfonyTransport')) {
                $transport = $mailer->getSymfonyTransport();

                if (method_exists($transport, 'stop')) {
                    $transport->stop();
                }
            }
        } catch (Throwable) {
            // A transport that cannot be stopped is already disconnected.
        }
    }
}
