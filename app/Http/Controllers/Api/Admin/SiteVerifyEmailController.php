<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ResolvesTestSubscriber;
use App\Http\Controllers\Concerns\SendsAdminTestEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendTestSiteEmailRequest;
use App\Http\Requests\Admin\UpdateSiteVerifyEmailRequest;
use App\Http\Resources\SiteVerifyEmailResource;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\SiteVerifyEmail;
use App\Models\VerifyEmailSend;
use App\Services\VerifyEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Per-site "verify your email" template management for the admin panel.
 *
 * Mirrors {@see SiteEmailTemplateController}: each site owns one editable
 * template (auto-created with defaults on first access), a live preview renders
 * edits without saving, and "send test" delivers the saved template through the
 * .env SMTP mailer (config('mail.admin_mailer')).
 */
class SiteVerifyEmailController extends Controller
{
    use ResolvesTestSubscriber;

    use SendsAdminTestEmail;

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
     * the .env SMTP mailer (config('mail.admin_mailer')) using the template's own
     * from_name + from_email.
     */
    public function sendTest(SendTestSiteEmailRequest $request, Site $site): JsonResponse
    {
        $to = $request->validated('to');
        $newsletter = $this->testSubscriberFor($site, $to);
        // The optional name from the test modal drives the "Dear {name}," greeting.
        // Set in memory only (not saved) so testing never overwrites a real
        // subscriber's stored name; a blank name yields no greeting.
        $newsletter->full_name = $request->validated('name');

        // Identical send path to the subscription + promotion tests — only the
        // template built into the mailable differs (that was the whole bug).
        return $this->sendAdminTestEmail(
            $this->emails->mailForSubscriber($site, $newsletter),
            $to,
            /*
             * Recorded in the verify-email history, flagged as a test.
             *
             * The operator pressing this button is usually checking something
             * they already doubt, so the send leaving no trace was the worst
             * possible behaviour — and a failure leaves one too, which is the
             * case that matters most.
             *
             * `newsletter_id` stays null: the row the test borrows may be a
             * real subscriber, and recording them here would read as "we sent
             * this person a verification" when nobody asked for one.
             */
            fn (bool $ok, ?string $error): VerifyEmailSend => VerifyEmailSend::create([
                'site_id' => $site->id,
                'email'   => $to,
                'status'  => $ok ? VerifyEmailSend::STATUS_SUCCESS : VerifyEmailSend::STATUS_FAILED,
                'error'   => $error === null ? null : mb_strimwidth($error, 0, 500, '…'),
                'is_test' => true,
                'sent_at' => now(),
            ]),
        );
    }
}
