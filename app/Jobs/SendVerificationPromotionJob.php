<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PromotionMailerException;
use App\Models\EmailSchedule;
use App\Models\Newsletter;
use App\Models\PromotionEmailHistory;
use App\Models\Unsubscribe;
use App\Models\VerificationPromotionEmail;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\PromotionEmailService;
use App\Support\Mail\SiteSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the ONE global post-verification promotion to a single subscriber.
 *
 * One job per subscriber rather than a batch (as {@see SendPromotionBatchJob}
 * does): these trickle in a few at a time as people verify, so there is no batch
 * to amortise, and per-subscriber jobs keep one bad address from touching
 * anyone else.
 *
 * ONCE-EVER DELIVERY. The guard is a conditional UPDATE on
 * `newsletters.verification_promotion_sent_at` — atomic in InnoDB, so exactly
 * one caller can ever observe one affected row for a given subscriber. Every
 * duplication route collapses onto it: an overlapping cron, a re-queued job, a
 * queue retry, several workers, repeated clicks on the verify link. It is a
 * database-level guarantee, not an application-level check.
 *
 * The claim is RELEASED if the send fails, so a transient SendGrid outage does
 * not permanently consume the subscriber's one chance; the next sweep picks them
 * up again. The failure is recorded in the promotion history either way, and the
 * subscriber is never marked as successfully sent.
 *
 * Transport comes from the feature's own saved provider via
 * {@see PromotionMailerFactory}. Choosing SendGrid here means the .env
 * SENDGRID_API_KEY (the same key the public verify emails use) — that is the
 * configured behaviour, not a fallback: an unset key throws rather than
 * silently switching transport. Mailgun and stored-key SendGrid rows still
 * resolve their own admin-managed credential.
 *
 * Runs on the LOW queue with the other marketing mail.
 */
class SendVerificationPromotionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'low';

    /** One retry for transient infrastructure failures. */
    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(public readonly int $newsletterId)
    {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(PromotionEmailService $promotions, PromotionMailerFactory $mailers): void
    {
        $config = VerificationPromotionEmail::current();

        // Re-checked at send time, not just at dispatch: the admin may have
        // switched the feature off while this job sat in the queue.
        if (! $config->active) {
            return;
        }

        $newsletter = Newsletter::with('site')->find($this->newsletterId);

        if ($newsletter === null || $newsletter->site === null) {
            return;
        }

        // Re-verify every precondition here rather than trusting the sweep that
        // queued us — the row may have changed in between.
        if (! $newsletter->verified || $newsletter->verification_promotion_sent_at !== null) {
            return;
        }

        if ($this->delayNotElapsed($newsletter, $config)) {
            return;
        }

        if (Unsubscribe::has($newsletter->site_id, $newsletter->email, Unsubscribe::TYPE_PROMOTION)) {
            return;
        }

        // Resolve the transport BEFORE claiming. A missing/disabled credential
        // must not burn the subscriber's single claim — log it and leave them
        // eligible for the next sweep, once an admin has fixed the setting.
        try {
            $resolved = $mailers->resolve($config->provider, $config->credentialId());
        } catch (PromotionMailerException $e) {
            Log::error('Post-verification promotion skipped: mail transport unavailable', [
                'newsletter_id' => $newsletter->id,
                'provider'      => $config->provider,
                'error'         => $e->getMessage(),
            ]);

            return;
        }

        // ── The claim. Exactly one caller wins. ──────────────────────────────
        if (! $this->claim($newsletter)) {
            return;
        }

        try {
            $mailable = $promotions
                ->mailFor(
                    $newsletter->site,
                    $config,
                    $newsletter->email,
                    $newsletter->unsubscribeTokenFor(Unsubscribe::TYPE_PROMOTION),
                    $newsletter->full_name,
                )
                ->usingFromAddress($this->fromAddress($config, $newsletter, $resolved->fromAddress));

            $resolved->mailer->to($newsletter->email)->send($mailable);
        } catch (Throwable $e) {
            // Hand the claim back so a later sweep can retry, and record the
            // failure. Deliberately NOT marked as sent.
            $this->releaseClaim($newsletter);
            $this->record($newsletter, PromotionEmailHistory::STATUS_FAILED, $e->getMessage());

            Log::warning('Post-verification promotion send failed', [
                'newsletter_id' => $newsletter->id,
                'email'         => $newsletter->email,
                'error'         => $e->getMessage(),
            ]);

            // Rethrow so the queue's own retry gets a turn; the claim is already
            // released, so the retry re-runs every precondition cleanly.
            throw $e;
        }

        $this->record($newsletter, PromotionEmailHistory::STATUS_SUCCESS);
    }

    /**
     * The From address this send must use.
     *
     * SendGrid only accepts mail from a sender it has authenticated. The default
     * for every promotion transport is config('mail.from.address') — the SMTP
     * mailbox — which is correct for SMTP and Mailgun but NOT for SendGrid: that
     * domain is not verified there, so the message is silently dropped or
     * spam-filed (it sends, returns no error, and never arrives).
     *
     * Over the .env SendGrid transport it therefore reuses {@see SiteSender},
     * the same helper that picks the From for the public verify emails on that
     * exact transport — one place decides what a SendGrid-authenticated sender
     * is, for both of the mails that go out through it.
     */
    private function fromAddress(
        VerificationPromotionEmail $config,
        Newsletter $newsletter,
        ?string $default,
    ): ?string {
        if ($config->provider !== EmailSchedule::PROVIDER_SENDGRID_ENV) {
            return $default;
        }

        return SiteSender::verificationAddress($newsletter->site) ?: $default;
    }

    /**
     * Has `verified_at + delay_minutes` passed?
     *
     * The same rule the sweep applies, re-evaluated here because the delay may
     * have been raised while this job was queued. Measured from the moment the
     * subscriber clicked the verify link — never from when they subscribed.
     *
     * A NULL verified_at (never confirmed, or a row predating the column) is
     * treated as "not elapsed", so it can never be sent to.
     */
    private function delayNotElapsed(Newsletter $newsletter, VerificationPromotionEmail $config): bool
    {
        if ($newsletter->verified_at === null) {
            return true;
        }

        $eligibleAt = Carbon::parse($newsletter->verified_at)
            ->addMinutes(max(0, (int) $config->delay_minutes));

        return $eligibleAt->isFuture();
    }

    /**
     * Atomically take ownership of this subscriber's single send.
     *
     * The `whereNull` in the UPDATE is the whole guarantee: InnoDB serialises
     * the row write, so of any number of concurrent callers exactly one sees an
     * affected-row count of 1.
     */
    private function claim(Newsletter $newsletter): bool
    {
        $claimed = Newsletter::whereKey($newsletter->id)
            ->whereNull('verification_promotion_sent_at')
            ->update(['verification_promotion_sent_at' => Carbon::now()]);

        return $claimed === 1;
    }

    /** Give the claim back after a failed send so the subscriber stays eligible. */
    private function releaseClaim(Newsletter $newsletter): void
    {
        try {
            Newsletter::whereKey($newsletter->id)
                ->update(['verification_promotion_sent_at' => null]);
        } catch (Throwable $e) {
            Log::warning('Could not release post-verification promotion claim', [
                'newsletter_id' => $newsletter->id,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Append the outcome to the shared promotion history so this feature's sends
     * appear in the existing admin history view alongside campaign sends.
     *
     * Best-effort: the mail has already gone out, so a history write failure is
     * logged but never fails the job (which would re-send).
     */
    private function record(Newsletter $newsletter, string $status, ?string $error = null): void
    {
        try {
            PromotionEmailHistory::recordAttempts(
                $newsletter->site_id,
                [$newsletter->email => $status],
                $error === null ? [] : [$newsletter->email => $error],
            );
        } catch (Throwable $e) {
            Log::warning('Post-verification promotion history write failed', [
                'newsletter_id' => $newsletter->id,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('Post-verification promotion job failed', [
            'newsletter_id' => $this->newsletterId,
            'error'         => $e->getMessage(),
        ]);
    }
}
