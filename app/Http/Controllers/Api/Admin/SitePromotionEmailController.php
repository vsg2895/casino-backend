<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendTestSiteEmailRequest;
use App\Http\Requests\Admin\UpdateSitePromotionEmailRequest;
use App\Http\Resources\SitePromotionEmailResource;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\SitePromotionEmail;
use App\Services\PromotionEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Per-site promotion email template management for the admin panel.
 *
 * Each site owns one editable promotion template (auto-created with defaults on
 * first access). Live preview renders edits without saving; "send test" delivers
 * the saved template through the .env SMTP mailer (config('mail.test_mailer'))
 * so admins verify layout via their own inbox. Mirrors
 * SiteEmailTemplateController but for the marketing offer blast.
 */
class SitePromotionEmailController extends Controller
{
    public function __construct(private readonly PromotionEmailService $emails) {}

    /** Return the site's promotion template, creating defaults the first time. */
    public function show(Site $site): JsonResponse
    {
        // Pin to 200: first access auto-creates the row, which would otherwise
        // make the resource respond 201 — wrong for an idempotent GET.
        return (new SitePromotionEmailResource($site->promotionEmailOrDefault()))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /** Persist edits to the site's promotion template. */
    public function update(UpdateSitePromotionEmailRequest $request, Site $site): JsonResponse
    {
        $template = $site->promotionEmailOrDefault();
        $template->update($request->validated());

        return (new SitePromotionEmailResource($template->refresh()))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Render the (possibly unsaved) template to HTML for the live preview pane.
     * Accepts the same payload as update() but never writes to the database.
     */
    public function preview(UpdateSitePromotionEmailRequest $request, Site $site): JsonResponse
    {
        $template = new SitePromotionEmail($request->validated());
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
     * sender still accept the test.
     *
     * The test recipient is registered as a subscriber (firstOrCreate) so the
     * email carries that subscriber's REAL promotion token — the unsubscribe
     * link and the RFC 8058 one-click header therefore work end-to-end.
     */
    public function sendTest(SendTestSiteEmailRequest $request, Site $site): JsonResponse
    {
        $template = $site->promotionEmailOrDefault();
        $to = $request->validated('to');
        $newsletter = Newsletter::firstOrCreate(['site_id' => $site->id, 'email' => $to]);
        // The optional name from the test modal drives the "Dear {name}," greeting.
        // Set in memory only (not saved) so testing never overwrites a real
        // subscriber's stored name; a blank name yields no greeting.
        $newsletter->full_name = $request->validated('name');

        try {
            // Test sends use the template's own from_name + from_email (admin
            // CRUD), delivered over the .env SMTP mailer (config('mail.test_mailer')).
            $mailable = $this->emails->mailForSubscriber($site, $template, $newsletter);

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
