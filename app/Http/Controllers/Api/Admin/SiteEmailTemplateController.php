<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendTestSiteEmailRequest;
use App\Http\Requests\Admin\UpdateSiteEmailTemplateRequest;
use App\Http\Resources\SiteEmailTemplateResource;
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
 * saved template through the shared SendGrid mailer.
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

    /** Send a one-off test of the saved template to an arbitrary address. */
    public function sendTest(SendTestSiteEmailRequest $request, Site $site): JsonResponse
    {
        $template = $site->emailTemplateOrDefault();
        $to = $request->validated('to');

        try {
            Mail::mailer('sendgrid')
                ->to($to)
                ->send($this->emails->previewMail($site, $template, $to));
        } catch (Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'Could not send via SendGrid: ' . $e->getMessage(),
            ], 502);
        }

        return response()->json(['ok' => true, 'message' => "Test email sent to {$to}."]);
    }
}
