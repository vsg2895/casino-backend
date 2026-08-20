<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\PromotionMailerException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendTestSiteEmailRequest;
use App\Http\Requests\Admin\UpdateVerificationPromotionEmailRequest;
use App\Http\Resources\VerificationPromotionEmailResource;
use App\Models\EmailSchedule;
use App\Models\Site;
use App\Models\VerificationPromotionEmail;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\PromotionEmailService;
use App\Support\Mail\SiteSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin management of the ONE global post-verification promotion.
 *
 * Deliberately has no {site} parameter anywhere: a single template serves
 * subscribers from every registered site. Otherwise it follows
 * {@see SitePromotionEmailController} — show (materialising defaults on first
 * access), update, live preview of unsaved edits, and a test send.
 *
 * THE TEST SEND IS THE ONE DIVERGENCE. Every other admin test button routes
 * through {@see \App\Http\Controllers\Concerns\SendsAdminTestEmail}, which pins
 * delivery to SMTP. That is wrong for this feature: the point of the button here
 * is to prove the transport the REAL promotion will use, so it resolves the same
 * saved provider + credential the job resolves. A test that succeeded over SMTP
 * while the configured SendGrid key was broken would be worse than no test.
 */
class VerificationPromotionEmailController extends Controller
{
    public function __construct(
        private readonly PromotionEmailService $promotions,
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
        $site = $this->sampleSite();

        if ($site === null) {
            return response()->json([
                'message' => 'Register a site before previewing — the template renders against one.',
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
        $site = $this->sampleSite();

        if ($site === null) {
            return response()->json([
                'ok'      => false,
                'message' => 'Register a site before sending a test — the template renders against one.',
            ], 422);
        }

        // Same resolution the job performs. A missing/disabled key fails loudly
        // here instead of falling back to another transport and reporting a
        // success that proves nothing.
        try {
            $resolved = $this->mailers->resolve($config->provider, $config->credentialId());
        } catch (PromotionMailerException $e) {
            return response()->json([
                'ok' => false,
                // The exception describes the configuration problem (which
                // provider, which key state) and never contains key material.
                'message' => 'Mail transport unavailable: ' . $e->getMessage(),
            ], 422);
        }

        try {
            // Same From resolution as the real send (see
            // SendVerificationPromotionJob::fromAddress) — over SendGrid the
            // sender must be one SendGrid has authenticated, or the test
            // "succeeds" and never arrives, proving nothing.
            $from = $config->provider === EmailSchedule::PROVIDER_SENDGRID_ENV
                ? (SiteSender::verificationAddress($site) ?: $resolved->fromAddress)
                : $resolved->fromAddress;

            $mailable = $this->promotions
                ->previewMail($site, $config, $to, $request->validated('name'))
                ->usingFromAddress($from);

            $resolved->mailer->to($to)->send($mailable);
        } catch (Throwable $e) {
            Log::warning('Post-verification promotion test email failed', [
                'to'       => $to,
                'provider' => $config->provider,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'Could not send test email: ' . $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'ok'      => true,
            'message' => "Test email sent to {$to} via {$config->provider}.",
        ]);
    }

    /**
     * A site to render the global template against.
     *
     * Any active site will do — the template is brand-neutral by design and only
     * reads site_name / site_url, which every site has. Lowest id for a stable,
     * repeatable preview.
     */
    private function sampleSite(): ?Site
    {
        return Site::query()->where('active', true)->orderBy('id')->first()
            ?? Site::query()->orderBy('id')->first();
    }
}
