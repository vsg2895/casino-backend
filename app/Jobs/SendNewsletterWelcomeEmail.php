<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Newsletter;
use App\Models\Site;
use App\Models\Unsubscribe;
use App\Services\SubscriptionEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the "successfully subscribed" confirmation email for a single
 * subscription. Runs on the HIGH-priority queue so confirmation emails go out
 * promptly. Delivered through the shared `sendgrid` mailer (one API key for all
 * sites); the per-site editable template supplies copy + sender identity.
 *
 * Dispatched by ProcessNewsletterSubscription only for brand-new (or
 * re-activated) subscriptions.
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

    public function handle(SubscriptionEmailService $emails): void
    {
        $site = Site::find($this->siteId);

        // Site may have been deleted between subscribe and send — nothing to do.
        if ($site === null) {
            return;
        }

        // Respect the per-site toggle: when disabled, persist only, don't email.
        if (! $site->emailTemplateOrDefault()->active) {
            return;
        }

        // Respect a subscription-stream opt-out (defensive: normally cleared on
        // subscribe, but the subscriber may have unsubscribed in between).
        if (Unsubscribe::has($this->siteId, $this->email, Unsubscribe::TYPE_SUBSCRIPTION)) {
            return;
        }

        $newsletter = Newsletter::where('site_id', $this->siteId)
            ->where('email', $this->email)
            ->first();

        if ($newsletter === null) {
            return;
        }

        // Public subscriptions are delivered over SendGrid (config('mail.newsletter_mailer'));
        // admin "send test" actions use the .env SMTP mailer instead. From =
        // the site's name (from_name) on the SendGrid-verified address, so every
        // site's mail delivers (e.g. "Idev Affiliation <info@winpalack.com>").
        $mailable = $emails->mailForSubscriber($site, $newsletter);
        $mailable->fromAddressOverride = config('mail.newsletter_from_address');

        Mail::mailer(config('mail.newsletter_mailer'))
            ->to($this->email)
            ->send($mailable);
    }
}
