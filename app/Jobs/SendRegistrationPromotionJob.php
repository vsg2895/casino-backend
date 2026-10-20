<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PromotionMailerException;
use App\Models\ForumUser;
use App\Models\PromotionEmailHistory;
use App\Models\Unsubscribe;
use App\Models\VerificationPromotionEmail;
use App\Models\VerificationPromotionSend;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\PostVerificationPromotionEmailService;
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
 * Sends the post-verification promotion to one REGISTERED MEMBER.
 *
 * The sibling of {@see SendVerificationPromotionJob}, which does the same for a
 * newsletter subscriber. Same template, same settings, same delay — the only
 * difference is which audience the recipient came from, and therefore which
 * column holds the claim and which token the opt-out link carries.
 *
 * WHY A SEPARATE JOB rather than teaching the existing one about two models:
 * that job sends real marketing email and is the proven path. Threading a second
 * recipient type through its eight gates would put the subscriber flow at risk
 * to serve the member flow. The duplication is deliberate and bounded — the
 * email itself comes from the one shared service, so the two cannot send
 * different messages.
 *
 * THE CROSS-AUDIENCE GUARANTEE. Someone can register an account AND subscribe
 * with the same address, and they must not receive the same promotion twice.
 * Two guards, both shared with the sibling job so the two sides cannot
 * disagree about what "already received it" means:
 *
 *   - {@see VerificationPromotionSend::alreadySentTo()} — has this ADDRESS
 *     already been mailed this template, by either flow, at this site. It
 *     covers both orderings, whichever flow ran first;
 *   - a lock on the address, held across that check, the send and the history
 *     write, so two jobs that start at the same instant cannot both pass a
 *     check that only becomes true once one of them finishes.
 */
class SendRegistrationPromotionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Bulk fan-out, like every other promotion send. */
    public const string ON_QUEUE = 'low';

    /** One retry for transient infrastructure failures. */
    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(public readonly int $memberId)
    {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(PostVerificationPromotionEmailService $promotions, PromotionMailerFactory $mailers): void
    {
        $config = VerificationPromotionEmail::current();

        // Every gate logs WHY it stopped, at debug level — on a healthy system
        // the "already claimed" branch is the normal outcome of a retry, and
        // warning-level noise for expected behaviour trains people to ignore the
        // channel. The point is that "the promotion is not sending" is
        // answerable from the log alone.

        // Re-checked at send time: the admin may have switched the feature off
        // while this job sat in the queue.
        if (! $config->active) {
            $this->skipped('the feature was switched off after this job was queued');

            return;
        }

        $member = ForumUser::with('site')->find($this->memberId);

        if ($member === null) {
            $this->skipped('the member was deleted after this job was queued');

            return;
        }

        if ($member->email_verified_at === null) {
            $this->skipped('the member is not verified', $member->email);

            return;
        }

        /*
         * Consent, re-checked at send time like every other gate here.
         *
         * The sweep already filtered on it, but a member can withdraw consent
         * while the job sits in the queue, and this is marketing email: the
         * check that matters is the one closest to the send.
         */
        if ($member->marketing_consent_at === null) {
            $this->skipped('the member has not consented to marketing', $member->email);

            return;
        }

        if ($member->verification_promotion_sent_at !== null) {
            $this->skipped('already claimed by another worker or an earlier sweep', $member->email);

            return;
        }

        // The delay, re-evaluated here rather than trusted from the sweep: an
        // admin can raise it while this job is queued, and a member who was
        // eligible at dispatch may not be any more.
        if ($this->delayNotElapsed($member, $config)) {
            $this->skipped('the delay has not elapsed yet', $member->email);

            return;
        }

        if (Unsubscribe::hasAny((int) $member->site_id, (string) $member->email)) {
            $this->skipped('the address has opted out of at least one stream', $member->email);

            return;
        }

        /*
         * ── ONE ADDRESS, ONE SENDER AT A TIME ───────────────────────────────
         *
         * The mirror of the lock in {@see SendVerificationPromotionJob}, taking
         * the same key, because the whole point is that the two jobs contend
         * for it: the member row and the subscriber row are in different tables
         * and their claims cannot see one another, while the record the gate
         * below reads is only written after the mail goes out.
         *
         * Not acquiring is NOT an error and NOT a claim — the other job holds
         * this address right now, so the member is left for the next sweep, by
         * which time the winner's send record exists and the gate settles it.
         */
        $delivered = Cache::lock(
            VerificationPromotionSend::lockKeyFor((int) $member->site_id, (string) $member->email),
            VerificationPromotionSend::SEND_LOCK_SECONDS,
        )->get(fn (): bool => $this->deliver($member, $config, $promotions, $mailers));

        if ($delivered === false) {
            $this->skipped('another promotion job is working on this address right now', $member->email);
        }
    }

    /**
     * The critical section: decide, send, record — holding the address lock.
     *
     * Split out of {@see self::handle()} only so the lock can wrap it as one
     * closure; the order of the checks and the send is unchanged.
     *
     * Always returns true, so the caller can tell "the lock was not acquired"
     * (Laravel's `Lock::get()` returns false) from "this ran".
     */
    private function deliver(
        ForumUser $member,
        VerificationPromotionEmail $config,
        PostVerificationPromotionEmailService $promotions,
        PromotionMailerFactory $mailers,
    ): bool {
        /*
         * THE CROSS-AUDIENCE GATE.
         *
         * One person, one promotion. A member who also subscribed with the same
         * address already has a send record from the newsletter flow, and this
         * is the only check that sees it — the claim columns are on different
         * tables and neither knows about the other.
         *
         * Checked by email within the site, which is the only identity the two
         * audiences share.
         *
         * The query itself now lives on the model as
         * {@see VerificationPromotionSend::alreadySentTo()} — unchanged in what
         * it asks, but shared with the newsletter job so the two sides cannot
         * drift into disagreeing about what "already received it" means.
         */
        if (VerificationPromotionSend::alreadySentTo((int) $member->site_id, (string) $member->email)) {
            $this->skipped('this address already received the promotion through the newsletter flow', $member->email);

            // Claimed anyway, so the sweep stops reconsidering them every
            // minute for a decision that will never change.
            $this->claim($member);

            return true;
        }

        // Resolve the transport BEFORE claiming. A missing or disabled
        // credential must not burn the member's single claim — log it and leave
        // them eligible for the next sweep, once an admin has fixed the setting.
        try {
            $resolved = $mailers->resolve($config->provider, $config->credentialId());
        } catch (PromotionMailerException $e) {
            Log::error('Registration promotion skipped: mail transport unavailable', [
                'member_id' => $member->id,
                'provider'  => $config->provider,
                'error'     => $e->getMessage(),
            ]);

            return true;
        }

        // ── The claim. Exactly one caller wins. ──────────────────────────────
        if (! $this->claim($member)) {
            return true;
        }

        try {
            // No usingFromAddress(), for the same reason the subscriber job
            // gives: PromotionEmail::envelope() falls back to the template's own
            // from_email, which is the address configured in the Promotion After
            // Verification section — the sender this feature is specified to
            // use.
            $mailable = $promotions->mailFor(
                $member->site,
                $config,
                (string) $member->email,
                // The member's own opt-out token. Without one the unsubscribe
                // link would resolve to nothing: the public endpoint looks the
                // token up, and a member has no newsletter row to find.
                $member->unsubscribeTokenFor(Unsubscribe::TYPE_PROMOTION_AFTER_VERIFICATION),
                (string) $member->display_name,
            );

            $resolved->mailer->to((string) $member->email)->send($mailable);
        } catch (Throwable $e) {
            // Hand the claim back so a later sweep can retry, and record the
            // failure. Deliberately NOT marked as sent.
            $this->releaseClaim($member);
            $this->record($member, PromotionEmailHistory::STATUS_FAILED, $e->getMessage());

            Log::warning('Registration promotion send failed', [
                'member_id' => $member->id,
                'email'     => $member->email,
                'error'     => $e->getMessage(),
            ]);

            // Rethrow so the queue's own retry gets a turn; the claim is already
            // released, so the retry re-runs every precondition cleanly.
            throw $e;
        }

        $this->record($member, PromotionEmailHistory::STATUS_SUCCESS);
        return true;
    }

    /** Has the configured delay since verification elapsed? */
    private function delayNotElapsed(ForumUser $member, VerificationPromotionEmail $config): bool
    {
        $due = $member->email_verified_at->copy()->addMinutes(max(0, (int) $config->delay_minutes));

        return $due->isFuture();
    }

    /**
     * Take the member's single claim, atomically.
     *
     * `WHERE verification_promotion_sent_at IS NULL` in the UPDATE is what makes
     * this safe without a lock: InnoDB serialises the two writes and the loser
     * updates zero rows.
     */
    private function claim(ForumUser $member): bool
    {
        $claimed = ForumUser::whereKey($member->id)
            ->whereNull('verification_promotion_sent_at')
            ->update(['verification_promotion_sent_at' => Carbon::now()]);

        return $claimed === 1;
    }

    /** Give the claim back so a later sweep can try again. */
    private function releaseClaim(ForumUser $member): void
    {
        ForumUser::whereKey($member->id)->update(['verification_promotion_sent_at' => null]);
    }

    private function skipped(string $why, ?string $email = null): void
    {
        Log::debug('Registration promotion skipped: ' . $why, array_filter([
            'member_id' => $this->memberId,
            'email'     => $email,
        ]));
    }

    /**
     * Both history writes, exactly as the subscriber job does them.
     *
     * The shared `promotion_email_histories` table is load-bearing — the
     * 24-hour dedup reads it, so a campaign and this stream cannot both reach an
     * address on the same day — but it has no room for which audience this was,
     * so this stream's own log carries that.
     */
    private function record(ForumUser $member, string $status, ?string $error = null): void
    {
        try {
            PromotionEmailHistory::recordAttempts(
                (int) $member->site_id,
                [$member->email => $status],
                $error === null ? [] : [$member->email => $error],
                PromotionEmailHistory::STREAM_POST_VERIFICATION,
            );

            VerificationPromotionSend::create([
                'site_id'       => $member->site_id,
                'newsletter_id' => null,
                'source'        => VerificationPromotionSend::SOURCE_REGISTRATION,
                'forum_user_id' => $member->id,
                'email'         => $member->email,
                'status'        => $status === PromotionEmailHistory::STATUS_SUCCESS
                    ? VerificationPromotionSend::STATUS_SUCCESS
                    : VerificationPromotionSend::STATUS_FAILED,
                'error'         => $error === null ? null : mb_strimwidth($error, 0, 500, '…'),
                'sent_at'       => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Registration promotion history write failed', [
                'member_id' => $member->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
