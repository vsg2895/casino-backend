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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin management of the ONE global post-verification promotion.
 *
 * Deliberately has no {site} parameter anywhere: a single template serves
 * subscribers from every registered site. Otherwise, it follows
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
     * Rendered against a representative site so the {{site_name}} / {{site_url}}
     * placeholders resolve to something real — the same substitution each
     * subscriber's own site performs at send time.
     */
    public function preview(UpdateVerificationPromotionEmailRequest $request): JsonResponse
    {
        // The editor sends the picker's value as `preview_site_id` (it is part of
        // the saved payload now); `site_id` stays honoured for any caller still
        // using the older transient key.
        $site = $this->resolveSite(
            $request->integer('preview_site_id') ?: $request->integer('site_id'),
        );

        if ($site === null) {
            return response()->json([
                'message' => 'Register a site before previewing — the template renders against one.',
            ], 422);
        }

        // site_id is a preview-only selector, never a template column — strip it
        // before building the (unsaved) template model.
        $template = new VerificationPromotionEmail(
            Arr::except($request->validated(), 'site_id'),
        );

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
        // This button sends the SAVED template, so it falls back to the SAVED
        // preview site when the request does not name one.
        $site = $this->resolveSite(
            $request->integer('site_id') ?: $config->preview_site_id,
        );

        if ($site === null) {
            return response()->json([
                'ok'      => false,
                'message' => 'Register a site before sending a test — the template renders against one.',
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
     * The site to render the global template against.
     *
     * When the admin has picked one in the editor ($siteId), its {{site_name}} /
     * {{site_url}} are used, so the preview and test show exactly the copy a
     * subscriber from that site would receive. An unknown/inactive id, or none at
     * all, falls back to a representative site: any active site will do — the
     * template is brand-neutral by design and only reads site_name / site_url,
     * which every site has. Lowest id for a stable, repeatable default.
     */
    private function resolveSite(?int $siteId): ?Site
    {
        if ($siteId !== null && $siteId > 0) {
            $chosen = Site::query()->where('active', true)->find($siteId);
            if ($chosen !== null) {
                return $chosen;
            }
        }

        return Site::query()->where('active', true)->orderBy('id')->first()
            ?? Site::query()->orderBy('id')->first();
    }
}
