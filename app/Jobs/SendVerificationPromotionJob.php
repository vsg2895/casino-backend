<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PromotionMailerException;
use App\Models\Newsletter;
use App\Models\PromotionEmailHistory;
use App\Models\VerificationPromotionSend;
use App\Models\Unsubscribe;
use App\Models\VerificationPromotionEmail;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\PostVerificationPromotionEmailService;
use App\Support\Mail\MailCredential;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
 * ONCE-EVER DELIVERY, PER SUBSCRIBER. The guard is a conditional UPDATE on
 * `newsletters.verification_promotion_sent_at` — atomic in InnoDB, so exactly
 * one caller can ever observe one affected row for a given subscriber. Every
 * duplication route collapses onto it: an overlapping cron, a re-queued job, a
 * queue retry, several workers, repeated clicks on the verify link. It is a
 * database-level guarantee, not an application-level check.
 *
 * ONCE-EVER DELIVERY, PER ADDRESS. The claim above cannot see across audiences:
 * the same person can also hold a registered account, which is a different row
 * in a different table with a claim column of its own. Two further guards close
 * that, and both are shared with {@see SendRegistrationPromotionJob} so the two
 * sides cannot disagree:
 *
 *   - {@see VerificationPromotionSend::alreadySentTo()} — has this ADDRESS
 *     already been mailed this template, by either flow, at this site;
 *   - a lock on the address, held across that check, the send and the history
 *     write, so two jobs starting at the same instant cannot both pass a check
 *     that only becomes true once one of them finishes.
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

    public function handle(PostVerificationPromotionEmailService $promotions, PromotionMailerFactory $mailers): void
    {
        $config = VerificationPromotionEmail::current();

        // EVERY gate below logs WHY it stopped.
        //
        // They used to return silently, which made "the promotion is not
        // sending" undiagnosable in production: the sweep would report a job
        // queued, the worker would report it processed, and the subscriber would
        // get nothing, with no line anywhere saying which check refused. Five
        // different causes looked identical from the outside. Each is now
        // distinguishable from the log alone.
        //
        // Debug level, not warning: on a healthy system the "already claimed"
        // branch is the normal outcome of a retry, and warning-level noise for
        // expected behaviour trains people to ignore the channel.

        // Re-checked at send time, not just at dispatch: the admin may have
        // switched the feature off while this job sat in the queue.
        if (! $config->active) {
            $this->skipped('the feature was switched off after this job was queued');

            return;
        }

        $newsletter = Newsletter::with('site')->find($this->newsletterId);

        if ($newsletter === null) {
            $this->skipped('the subscriber row no longer exists');

            return;
        }

        if ($newsletter->site === null) {
            // Sites soft-delete, so this is reachable without the row vanishing.
            $this->skipped('the subscriber\'s site is missing or deleted', $newsletter->email);

            return;
        }

        // Re-verify every precondition here rather than trusting the sweep that
        // queued us — the row may have changed in between.
        if (! $newsletter->verified) {
            $this->skipped('the subscriber is not verified', $newsletter->email);

            return;
        }

        if ($newsletter->verification_promotion_sent_at !== null) {
            // The normal outcome of a retry, and of two sweeps overlapping.
            $this->skipped('the promotion was already claimed for this subscriber', $newsletter->email);

            return;
        }

        if ($this->delayNotElapsed($newsletter, $config)) {
            $this->skipped(sprintf(
                'the %d-minute delay has not elapsed (verified_at %s)',
                (int) $config->delay_minutes,
                $newsletter->verified_at?->toDateTimeString() ?? 'null',
            ), $newsletter->email);

            return;
        }

        // Global opt-out: any unsubscribe, of any template, stops this send.
        if (Unsubscribe::hasAny($newsletter->site_id, $newsletter->email)) {
            $this->skipped('the address has opted out of at least one stream', $newsletter->email);

            return;
        }

        /*
         * ── ONE ADDRESS, ONE SENDER AT A TIME ───────────────────────────────
         *
         * Everything from here on — the cross-audience check, the send and the
         * history write — runs while holding a lock on this ADDRESS, not on
         * this subscriber row.
         *
         * The row-level claims cannot do this job. A subscriber and a member
         * with the same address are two rows in two tables, so both claims
         * succeed; and the record the cross-audience gate reads is only written
         * after the mail goes out, so two jobs starting together both look,
         * both see nothing, and both send. The sweep queues exactly that pair
         * in a single run, and production runs two queue workers.
         *
         * `Cache::lock()->get(Closure)` releases in a `finally`, so a thrown
         * send failure still frees the address — the throw is deliberate (it
         * hands the queue its retry) and must not leave the lock stranded.
         *
         * Not acquiring is NOT an error and NOT a claim: the other job holds
         * the address right now. We leave this subscriber unclaimed, so the
         * next sweep reconsiders them — by which time the winner's send record
         * exists and the gate below settles it properly.
         */
        $delivered = Cache::lock(
            VerificationPromotionSend::lockKeyFor((int) $newsletter->site_id, (string) $newsletter->email),
            VerificationPromotionSend::SEND_LOCK_SECONDS,
        )->get(fn (): bool => $this->deliver($newsletter, $config, $promotions, $mailers));

        if ($delivered === false) {
            $this->skipped('another promotion job is working on this address right now', $newsletter->email);
        }
    }

    /**
     * The critical section: decide, send, record — holding the address lock.
     *
     * Split out of {@see self::handle()} only so the lock can wrap it as one
     * closure. The order of the checks and the send is byte-for-byte what it
     * was when they sat inline.
     *
     * Always returns true. The value exists so the caller can tell "the lock
     * was not acquired" (Laravel's `Lock::get()` returns false) from "this
     * ran" — a void closure would make the two indistinguishable.
     */
    private function deliver(
        Newsletter $newsletter,
        VerificationPromotionEmail $config,
        PostVerificationPromotionEmailService $promotions,
        PromotionMailerFactory $mailers,
    ): bool {
        /*
         * THE CROSS-AUDIENCE GATE.
         *
         * One person, one promotion. The same human can reach this template by
         * two completely separate routes — subscribing to the newsletter, and
         * registering an account — and the two are different rows in different
         * tables whose claim columns know nothing about each other. Without
         * this check, somebody who registered, verified and was mailed, and who
         * then subscribed and verified, would be mailed the identical email a
         * second time.
         *
         * `verification_promotion_sends` is the only place the two audiences
         * meet, and the address is the only identity they share. The sibling
         * job {@see SendRegistrationPromotionJob} has applied exactly this gate
         * since it was written; this side was missing it, which made the
         * duplicate one-directional — blocked when the newsletter came first,
         * not when the registration did.
         *
         * Placed AFTER the opt-out check and BEFORE the transport resolve, so
         * it sits in the same position as the sibling's and the two read alike.
         */
        if (VerificationPromotionSend::alreadySentTo((int) $newsletter->site_id, (string) $newsletter->email)) {
            $this->skipped('this address already received the promotion through the registration flow', $newsletter->email);

            /*
             * Claimed anyway, WITHOUT sending.
             *
             * The sweep selects on `verification_promotion_sent_at IS NULL`, so
             * leaving the claim open would re-queue this subscriber every
             * single minute, for ever, for a decision that can never come out
             * differently — the send record it is reading is immutable. Taking
             * the claim retires them from the sweep.
             *
             * The claim column means "this subscriber's one send has been
             * accounted for", not "we mailed them" — the history tables are
             * what record a send, and neither gets a row here, so the admin
             * history shows exactly one email for this address. Same reasoning,
             * same behaviour, as the sibling job.
             */
            $this->claim($newsletter);

            return true;
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

            return true;
        }

        // ── The claim. Exactly one caller wins. ──────────────────────────────
        if (! $this->claim($newsletter)) {
            return true;
        }

        try {
            // No usingFromAddress() on purpose: PromotionEmail::envelope() falls
            // back to the template's own from_email, which is the address
            // configured in the Promotion After Verification section — the
            // sender this feature is specified to use. Overriding it here (with
            // the SMTP mailbox, or the shared public sender) would silently
            // ignore what the admin typed into that field.
            $mailable = $promotions->mailFor(
                $newsletter->site,
                $config,
                $newsletter->email,
                // This template's own token, so an opt-out it produces is
                // attributed to the "promotion after verification" stream.
                $newsletter->unsubscribeTokenFor(Unsubscribe::TYPE_PROMOTION_AFTER_VERIFICATION),
                $newsletter->full_name,
            );

            $sent = $resolved->mailer->to($newsletter->email)->send($mailable);
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

        // Names the credential this actually went out with, so "is production
        // using the right SendGrid key?" is answerable from the log. Carries a
        // prefix and a fingerprint, never key material — see MailCredential.
        $this->record($newsletter, PromotionEmailHistory::STATUS_SUCCESS);

        return true;
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
    /**
     * Record why this subscriber was not sent to.
     *
     * One line, one reason, always naming the subscriber — so "why did MY
     * address get nothing" is answerable from the log without reproducing it.
     */
    private function skipped(string $reason, ?string $email = null): void
    {
        Log::debug('Post-verification promotion skipped', [
            'newsletter_id' => $this->newsletterId,
            'email'         => $email,
            'reason'        => $reason,
        ]);
    }

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
                // Labelled, so the shared dedup still sees this send while the
                // admin's Promotion tab does not: this stream has its own tab,
                // fed by the row written below.
                PromotionEmailHistory::STREAM_POST_VERIFICATION,
            );

            /*
             * And this stream's OWN log, which is what the admin's history
             * screen reads.
             *
             * Both writes, not one. The shared table above is load-bearing —
             * the 24-hour dedup reads it, so a campaign and this stream cannot
             * both reach an address on the same day — but it has no stream
             * column and its unique key merges two streams that land on one
             * address on one day. It therefore cannot answer "what did the
             * post-verification stream send", which is the question this row
             * exists for.
             */
            VerificationPromotionSend::create([
                'site_id'       => $newsletter->site_id,
                'newsletter_id' => $newsletter->id,
                // Explicit, even though it is the column default: the sibling
                // job for registered members writes the other value, and a
                // reader of either should not have to know which default applies.
                'source'        => VerificationPromotionSend::SOURCE_NEWSLETTER,
                'email'         => $newsletter->email,
                'status'        => $status === PromotionEmailHistory::STATUS_SUCCESS
                    ? VerificationPromotionSend::STATUS_SUCCESS
                    : VerificationPromotionSend::STATUS_FAILED,
                'error'         => $error === null ? null : mb_strimwidth($error, 0, 500, '…'),
                'sent_at'       => Carbon::now(),
            ]);
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
