<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendTestSiteEmailRequest;
use App\Http\Requests\Admin\UpdateSiteEmailTemplateRequest;
use App\Http\Resources\SiteEmailTemplateResource;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\SiteEmailTemplate;
use App\Services\SubscriptionEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Per-site subscription email template management for the admin panel.
 *
 * Each site owns one editable template (auto-created with defaults on first
 * access). Live preview renders edits without saving; "send test" delivers the
 * saved template through the .env SMTP mailer (config('mail.test_mailer')) so
 * admins verify layout via their own inbox. Real subscriber confirmations go
 * out over SendGrid.
 */
class SiteEmailTemplateController extends Controller
{
    public function __construct(private readonly SubscriptionEmailService $emails) {}

    /** Return the site's template, creating defaults the first time. */
    public function show(Site $site): JsonResponse
    {
        // Pin to 200: first access auto-creates the row, which would otherwise
        // make the resource respond 201 — wrong for an idempotent GET.
        return (new SiteEmailTemplateResource($site->emailTemplateOrDefault()))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /** Persist edits to the site's template. */
    public function update(UpdateSiteEmailTemplateRequest $request, Site $site): JsonResponse
    {
        $template = $site->emailTemplateOrDefault();
        $template->update($request->validated());

        return (new SiteEmailTemplateResource($template->refresh()))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Render the (possibly unsaved) template to HTML for the live preview pane.
     * Accepts the same payload as update() but never writes to the database.
     */
    public function preview(UpdateSiteEmailTemplateRequest $request, Site $site): JsonResponse
    {
        $template = new SiteEmailTemplate($request->validated());
        $template->site_id = $site->id;

        $html = $this->emails->previewMail($site, $template)->render();

        return response()->json(['html' => $html]);
    }

    /**
     * Send a one-off test of the saved template to an arbitrary address.
     *
     * Delivered through the .env SMTP mailer (config('mail.test_mailer')) — not
     * SendGrid — so admins verify layout via their own SMTP inbox. The "from" is
     * overridden to the global MAIL_FROM_ADDRESS (rather than the template's
     * per-site sender) so strict SMTP servers that enforce an authenticated
     * sender still accept the test. Real subscriber confirmations still go out
     * over SendGrid with the per-site sender (see SendNewsletterWelcomeEmail).
     *
     * The test recipient is registered as a subscriber (firstOrCreate) so the
     * email carries that subscriber's REAL per-stream token — the unsubscribe
     * link and the RFC 8058 one-click header therefore work end-to-end when the
     * admin tries them.
     */
    public function sendTest(SendTestSiteEmailRequest $request, Site $site): JsonResponse
    {
        $to = $request->validated('to');
        $newsletter = Newsletter::firstOrCreate(['site_id' => $site->id, 'email' => $to]);

        try {
            // Test sends use the template's own from_name + from_email (admin
            // CRUD), delivered over the .env SMTP mailer (config('mail.test_mailer')).
            $mailable = $this->emails->mailForSubscriber($site, $newsletter);

            Mail::mailer(config('mail.test_mailer'))
                ->to($to)
                ->send($mailable);
        } catch (Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'Could not send test email: ' . $e->getMessage(),
            ], 502);
        }

        return response()->json(['ok' => true, 'message' => "Test email sent to {$to}."]);
    }
}
