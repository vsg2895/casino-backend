<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PromotionMailerException;
use App\Http\Controllers\Concerns\SendsAdminTestEmail;
use App\Models\Site;
use App\Models\VerificationPromotionEmail;
use App\Models\VerificationPromotionSend;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\Mail\PromotionTestSendResult;
use App\Support\Mail\MailCredential;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The post-verification promotion's "Send test": the SAVED template through the
 * SAVED transport, recorded in this stream's own history.
 *
 * DELIBERATELY NOT {@see SendsAdminTestEmail}.
 * That shared path sends the per-site templates over the .env SMTP mailer, which
 * is right for them. This test must mirror the REAL send exactly, so it goes
 * through the same provider and the same sender (this section's own from_email).
 * A green result therefore proves the automatic promotion's delivery path.
 *
 * It also ignores the Enable switch: that switch pauses the AUTOMATIC send, and
 * an admin must still be able to test a paused template before turning it on.
 */
final class PostVerificationPromotionTestSender
{
    public function __construct(
        private readonly PostVerificationPromotionEmailService $promotions,
        private readonly PromotionMailerFactory $mailers,
    ) {}

    /**
     * @param  int|null  $siteId  The site the HISTORY ROW is filed under; null
     *                            falls back to {@see PostVerificationPromotionEmailService::previewSite()}.
     */
    public function send(VerificationPromotionEmail $config, ?int $siteId, string $to, ?string $name): PromotionTestSendResult
    {
        /*
         * WHICH SITE THIS TEST IS ATTRIBUTED TO.
         *
         * The site does not change what the recipient receives — this template
         * is pinned to one brand on the server, so the mail is byte-identical
         * whichever site is chosen. What it changes is the HISTORY ROW: every
         * test writes to `verification_promotion_sends`, and that table is
         * site-scoped, so the row shows up under whichever site is recorded
         * here and is reachable by the "Site" filter on the After-verification
         * tab.
         *
         * It used to be the preview site — the first ACTIVE site by id — which
         * meant every test on every brand was filed under site 1. The fallback
         * is unchanged, so a caller that sends no `site_id` — including any
         * older admin bundle still deployed — behaves exactly as before.
         */
        $site = $siteId === null
            ? $this->promotions->previewSite()
            : Site::query()->whereKey($siteId)->first();

        if ($site === null) {
            return PromotionTestSendResult::notAttempted(
                'Register a site before sending a test — the unsubscribe link is built from one.',
            );
        }

        // Which credential this test will use. Resolved BEFORE the transport so
        // it can be reported even when the transport itself fails — that is the
        // case where knowing the key matters most.
        $credential = MailCredential::describe($config->provider, $config->credentialId());

        // Same resolution the job performs. A missing/disabled key fails loudly
        // here instead of falling back to another transport and reporting a
        // success that proves nothing.
        try {
            $resolved = $this->mailers->resolve($config->provider, $config->credentialId());
        } catch (PromotionMailerException $e) {
            Log::warning('Post-verification promotion test email: transport unavailable', [
                'to' => $to,
                'provider' => $config->provider,
                'error' => $e->getMessage(),
                ...$credential,
            ]);

            // The exception describes the configuration problem (which
            // provider, which key state) and never contains key material.
            return PromotionTestSendResult::notAttempted(
                'Mail transport unavailable ('.$credential['source'].'): '.$e->getMessage(),
            );
        }

        // The sender is the one configured in THIS section, exactly as the real
        // send uses it: PromotionEmail::envelope() falls back to the template's
        // own from_email when no override is applied, so the test proves the
        // same From the automatic promotion will use.
        $from = (string) $config->from_email;

        try {
            // No usingFromAddress(): the mailable already takes from_email from
            // the template, which is what this section configures.
            $mailable = $this->promotions->previewMail($site, $config, $to, $name);

            $sent = $resolved->mailer->to($to)->send($mailable);
        } catch (Throwable $e) {
            Log::warning('Post-verification promotion test email failed', [
                'to' => $to,
                'provider' => $config->provider,
                'from' => $from,
                'error' => $e->getMessage(),
                ...$credential,
            ]);

            $this->record($site, $to, VerificationPromotionSend::STATUS_FAILED, $e->getMessage());

            return PromotionTestSendResult::failed(
                'Could not send test email via '.$credential['source']
                    .' ('.$credential['key_prefix'].' '.$credential['key_fingerprint'].'): '
                    .$e->getMessage(),
            );
        }

        // Logged on SUCCESS too, not just failure. This button is the fastest
        // way to answer "which key is production actually sending with?", and
        // that answer is worthless if it is only recorded when things break.
        // Fingerprint, never key material — see MailCredential.
        // SendGrid's own id for the message — the handle for looking up its
        // real fate in the Activity Feed when it does not appear in an inbox.
        $messageId = $sent?->getSymfonySentMessage()?->getMessageId();

        $this->record($site, $to, VerificationPromotionSend::STATUS_SUCCESS);

        Log::info('Post-verification promotion test email sent', [
            'to' => $to,
            'provider' => $config->provider,
            'from' => $from,
            'message_id' => $messageId,
            ...$credential,
        ]);

        // The credential is echoed back so it can be checked from the admin
        // panel alone, with no shell access. Safe to surface: an
        // already-authenticated screen, and a one-way fingerprint.
        return PromotionTestSendResult::sent(
            "Test email sent to {$to} from {$from} via {$credential['source']}"
                ." (key {$credential['key_prefix']} fingerprint {$credential['key_fingerprint']})"
                .($messageId ? " — SendGrid id {$messageId}" : '').'.',
        );
    }

    /**
     * Record a "Send test" in this stream's own history.
     *
     * A test used to leave an email and no evidence — the one case where
     * evidence is wanted most, because the operator is testing precisely
     * because something is in doubt. Failures are recorded too.
     *
     * Flagged `is_test`, and deliberately NOT written to the shared promotion
     * history: that table drives the 24-hour dedup, and a test must never make
     * a real subscriber's send get skipped.
     */
    private function record(Site $site, string $to, string $status, ?string $error = null): void
    {
        try {
            VerificationPromotionSend::create([
                'site_id' => $site->id,
                'email' => $to,
                'status' => $status,
                'error' => $error === null ? null : mb_strimwidth($error, 0, 500, '…'),
                'is_test' => true,
                'sent_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            // The mail has already gone; a history write must not turn a
            // successful test into an error response.
            Log::warning('Post-verification test history write failed', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
