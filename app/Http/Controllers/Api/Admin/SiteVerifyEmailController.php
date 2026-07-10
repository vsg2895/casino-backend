<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendTestSiteEmailRequest;
use App\Http\Requests\Admin\UpdateSiteVerifyEmailRequest;
use App\Http\Resources\SiteVerifyEmailResource;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\SiteVerifyEmail;
use App\Services\VerifyEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Per-site "verify your email" template management for the admin panel.
 *
 * Mirrors {@see SiteEmailTemplateController}: each site owns one editable
 * template (auto-created with defaults on first access), a live preview renders
 * edits without saving, and "send test" delivers the saved template through the
 * .env SMTP mailer (config('mail.test_mailer')).
 */
class SiteVerifyEmailController extends Controller
{
    public function __construct(private readonly VerifyEmailService $emails) {}

    /** Return the site's template, creating defaults the first time. */
    public function show(Site $site): JsonResponse
    {
        return (new SiteVerifyEmailResource($site->verifyEmailOrDefault()))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /** Persist edits to the site's template. */
    public function update(UpdateSiteVerifyEmailRequest $request, Site $site): JsonResponse
    {
        $template = $site->verifyEmailOrDefault();
        $template->update($request->validated());

        return (new SiteVerifyEmailResource($template->refresh()))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /** Render the (possibly unsaved) template to HTML for the live preview pane. */
    public function preview(UpdateSiteVerifyEmailRequest $request, Site $site): JsonResponse
    {
        $template = new SiteVerifyEmail($request->validated());
        $template->site_id = $site->id;

        $html = $this->emails->previewMail($site, $template)->render();

        return response()->json(['html' => $html]);
    }

    /**
     * Send a one-off test of the saved template to an arbitrary address, over
     * the .env SMTP mailer (config('mail.test_mailer')) using the template's own
     * from_name + from_email.
     */
    public function sendTest(SendTestSiteEmailRequest $request, Site $site): JsonResponse
    {
        $to = $request->validated('to');
        $newsletter = Newsletter::firstOrCreate(['site_id' => $site->id, 'email' => $to]);
        // The optional name from the test modal drives the "Dear {name}," greeting.
        // Set in memory only (not saved) so testing never overwrites a real
        // subscriber's stored name; a blank name yields no greeting.
        $newsletter->full_name = $request->validated('name');

        try {
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
