<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Newsletter;
use App\Models\Site;
use App\Models\Unsubscribe;
use App\Models\VerifyEmailSend;
use App\Services\VerifyEmailService;
use App\Support\Mail\SiteSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the "verify your email" (double opt-in) email for a single
 * subscription. Runs on the HIGH-priority queue so it goes out promptly.
 * Delivered through the shared `sendgrid` mailer (one API key for all sites);
 * the per-site editable VERIFY template supplies copy + sender identity, and a
 * verify link lets the subscriber confirm their address.
 *
 * Every site sends this verify email on subscribe (it replaced the old
 * "successfully subscribed" confirmation). Dispatched by
 * ProcessNewsletterSubscription only for brand-new (or re-activated)
 * subscriptions.
 */
class SendNewsletterWelcomeEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Priority queue this job runs on (worker: --queue=high,low). */
    public const string ON_QUEUE = 'high';

    public function __construct(
        public readonly int $siteId,
        public readonly string $email,
    ) {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(VerifyEmailService $emails): void
    {
        $site = Site::find($this->siteId);

        // Site may have been deleted between subscribe and send — nothing to do.
        if ($site === null) {
            return;
        }

        // Respect the per-site toggle: when the verify template is disabled,
        // persist only, don't email.
        if (! $site->verifyEmailOrDefault()->active) {
            return;
        }

        // Respect a global opt-out (defensive: normally cleared on subscribe, but
        // the subscriber may have unsubscribed in between). Any opt-out, of any
        // template, stops this send.
        if (Unsubscribe::hasAny($this->siteId, $this->email)) {
            return;
        }

        $newsletter = Newsletter::where('site_id', $this->siteId)
            ->where('email', $this->email)
            ->first();

        if ($newsletter === null) {
            return;
        }

        // Public verification emails go over the SendGrid Web API
        // (config('mail.public_mailer')). The From DOMAIN is resolved per-site so
        // each domain is authenticated in SendGrid independently — subscribe on
        // idevaffiliation.com → from @idevaffiliation.com. The display name stays
        // the template's from_name (the site name). Admin mail uses SMTP instead.
        $mailable = $emails->mailForSubscriber($site, $newsletter)
            ->usingFromAddress(SiteSender::verificationAddress($site));

        /*
         * Recorded either way.
         *
         * This is the first email a subscriber ever gets and the one that
         * decides whether they are verified at all, so "was it sent, and did
         * the transport take it" has to be answerable afterwards — it used to
         * live only in the application log.
         *
         * The throw is RE-THROWN after the row is written: a failed send must
         * still fail the job so the queue retries it. Writing the history is
         * not allowed to swallow that.
         */
        try {
            Mail::mailer(config('mail.public_mailer'))
                ->to($this->email)
                ->send($mailable);
        } catch (Throwable $e) {
            $this->record($newsletter, VerifyEmailSend::STATUS_FAILED, $e->getMessage());

            throw $e;
        }

        $this->record($newsletter, VerifyEmailSend::STATUS_SUCCESS);
    }

    /**
     * Append the outcome to the verify-email history.
     *
     * Best-effort: the mail has already gone out (or definitively failed), so a
     * history write that itself fails is logged and never changes the job's
     * outcome — re-sending a verification because its LOG row would not insert
     * is the wrong trade.
     */
    private function record(Newsletter $newsletter, string $status, ?string $error = null): void
    {
        try {
            VerifyEmailSend::create([
                'site_id'       => $newsletter->site_id,
                'newsletter_id' => $newsletter->id,
                'email'         => $newsletter->email,
                'status'        => $status,
                'error'         => $error === null ? null : mb_strimwidth($error, 0, 500, '…'),
                'sent_at'       => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Verify-email history write failed', [
                'newsletter_id' => $newsletter->id,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
