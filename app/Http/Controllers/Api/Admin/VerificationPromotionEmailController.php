<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\PromotionMailerException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendTestSiteEmailRequest;
use App\Http\Requests\Admin\UpdateVerificationPromotionEmailRequest;
use App\Http\Resources\VerificationPromotionEmailResource;
use App\Models\Site;
use App\Models\VerificationPromotionEmail;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\PostVerificationPromotionEmailService;
use App\Support\Mail\MailCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin management of the ONE global post-verification promotion.
 *
 * Deliberately has no {site} parameter anywhere: a single template serves
 * subscribers from every registered site, and since the branding is pinned to
 * Winpalack in config('promotions.after_verification') there is no longer
 * anything for a site to resolve either — the picker this page used to carry is
 * gone. Otherwise, it follows
 * {@see SitePromotionEmailController} — show (materialising defaults on first
 * access), update, live preview of unsaved edits, and a test send.
 *
 * THE TEST SEND DELIBERATELY DOES NOT USE
 * {@see \App\Http\Controllers\Concerns\SendsAdminTestEmail}. That shared path
 * sends the per-site templates over the .env SMTP mailer, which is right for
 * them — those buttons exist to prove the operator's own mail server works.
 * This feature's test has a different job: it must mirror its REAL send exactly,
 * so it goes through the same provider ({@see VerificationPromotionEmail}, the
 * .env SendGrid key) and the same sender (this section's own from_email). A
 * green result here therefore proves the automatic promotion's delivery path,
 * which a success over some other transport would not.
 *
 * It also ignores the Enable switch: that switch pauses the AUTOMATIC send, and
 * an admin must still be able to test a paused template before turning it on.
 */
class VerificationPromotionEmailController extends Controller
{
    public function __construct(
        private readonly PostVerificationPromotionEmailService $promotions,
        private readonly PromotionMailerFactory $mailers,
    ) {}

    /** The global template + settings, created with defaults the first time. */
    public function show(): JsonResponse
    {
        return response()->json(
            new VerificationPromotionEmailResource(VerificationPromotionEmail::current()),
        );
    }

    /** Persist edits to the global template + settings. */
    public function update(UpdateVerificationPromotionEmailRequest $request): JsonResponse
    {
        $config = VerificationPromotionEmail::current();
        $config->update($request->validated());

        return response()->json(
            new VerificationPromotionEmailResource($config->refresh()),
        );
    }

    /**
     * Render the (possibly unsaved) template to HTML for the live preview.
     *
     * There is no "preview as <site>" any more: the branding is fixed to
     * Winpalack in config, so every site would produce the same pixels. The
     * preview is therefore always what a subscriber receives, whichever of the
     * six they came from.
     *
     * A Site is still passed down, for the unsubscribe link alone — the preview
     * shows a realistic one rather than a dead string.
     */
    public function preview(UpdateVerificationPromotionEmailRequest $request): JsonResponse
    {
        $site = $this->unsubscribeSite();

        if ($site === null) {
            return response()->json([
                'message' => 'Register a site before previewing — the unsubscribe link is built from one.',
            ], 422);
        }

        $template = new VerificationPromotionEmail($request->validated());

        return response()->json([
            'html' => $this->promotions->previewMail($site, $template)->render(),
        ]);
    }

    /**
     * Send a one-off test of the SAVED template through the SAVED transport.
     *
     * Uses the configured provider/credential rather than SMTP (see the class
     * docblock), so a green result here means the real send path works.
     */
    public function sendTest(SendTestSiteEmailRequest $request): JsonResponse
    {
        $to = $request->validated('to');
        $config = VerificationPromotionEmail::current();
        // Any site: it only supplies the unsubscribe link. The branding the test
        // carries is the fixed one from config, exactly as a real send — which is
        // what makes this test byte-identical to what subscribers receive.
        $site = $this->unsubscribeSite();

        if ($site === null) {
            return response()->json([
                'ok'      => false,
                'message' => 'Register a site before sending a test — the unsubscribe link is built from one.',
            ], 422);
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
                'to'       => $to,
                'provider' => $config->provider,
                'error'    => $e->getMessage(),
                ...$credential,
            ]);

            return response()->json([
                'ok' => false,
                // The exception describes the configuration problem (which
                // provider, which key state) and never contains key material.
                'message' => 'Mail transport unavailable (' . $credential['source'] . '): ' . $e->getMessage(),
            ], 422);
        }

        // The sender is the one configured in THIS section, exactly as the real
        // send uses it: PromotionEmail::envelope() falls back to the template's
        // own from_email when no override is applied, so the test proves the
        // same From the automatic promotion will use.
        $from = (string) $config->from_email;

        try {
            // No usingFromAddress(): the mailable already takes from_email from
            // the template, which is what this section configures.
            $mailable = $this->promotions
                ->previewMail($site, $config, $to, $request->validated('name'));

            $sent = $resolved->mailer->to($to)->send($mailable);
        } catch (Throwable $e) {
            Log::warning('Post-verification promotion test email failed', [
                'to'       => $to,
                'provider' => $config->provider,
                'from'     => $from,
                'error'    => $e->getMessage(),
                ...$credential,
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'Could not send test email via ' . $credential['source']
                    . ' (' . $credential['key_prefix'] . ' ' . $credential['key_fingerprint'] . '): '
                    . $e->getMessage(),
            ], 502);
        }

        // Logged on SUCCESS too, not just failure. This button is the fastest
        // way to answer "which key is production actually sending with?", and
        // that answer is worthless if it is only recorded when things break.
        // Fingerprint, never key material — see MailCredential.
        // SendGrid's own id for the message — the handle for looking up its
        // real fate in the Activity Feed when it does not appear in an inbox.
        $messageId = $sent?->getSymfonySentMessage()?->getMessageId();

        Log::info('Post-verification promotion test email sent', [
            'to'         => $to,
            'provider'   => $config->provider,
            'from'       => $from,
            'message_id' => $messageId,
            ...$credential,
        ]);

        return response()->json([
            'ok' => true,
            // The credential is echoed back so it can be checked from the admin
            // panel alone, with no shell access. Safe to surface: an
            // already-authenticated screen, and a one-way fingerprint.
            'message' => "Test email sent to {$to} from {$from} via {$credential['source']}"
                . " (key {$credential['key_prefix']} fingerprint {$credential['key_fingerprint']})"
                . ($messageId ? " — SendGrid id {$messageId}" : '') . '.',
        ]);
    }

    /**
     * A site to build the preview/test UNSUBSCRIBE link from — nothing else.
     *
     * Since the branding is fixed in config, the choice no longer affects a
     * single visible string; it only decides which domain hosts the sample
     * opt-out link. Lowest active id, for a stable, repeatable default.
     */
    private function unsubscribeSite(): ?Site
    {
        return Site::query()->where('active', true)->orderBy('id')->first()
            ?? Site::query()->orderBy('id')->first();
    }
}
