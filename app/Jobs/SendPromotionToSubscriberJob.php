<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Newsletter;
use App\Models\Site;
use App\Models\Unsubscribe;
use App\Services\PromotionEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the site's promotion template to a single subscriber as part of a
 * scheduled campaign. Runs on the LOW-priority queue (marketing, not
 * transactional). Delivered via the newsletter mailer (native SendGrid API).
 *
 * Skips silently when the promotion template is off, the site/subscriber is
 * gone, or the address has opted out of the promotion stream.
 */
class SendPromotionToSubscriberJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'low';

    public function __construct(
        public readonly int $siteId,
        public readonly string $email,
    ) {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(PromotionEmailService $emails): void
    {
        $site = Site::find($this->siteId);
        if ($site === null) {
            return;
        }

        $template = $site->promotionEmailOrDefault();
        if (! $template->active) {
            return;
        }

        if (Unsubscribe::has($this->siteId, $this->email, Unsubscribe::TYPE_PROMOTION)) {
            return;
        }

        $newsletter = Newsletter::where('site_id', $this->siteId)
            ->where('email', $this->email)
            ->first();

        if ($newsletter === null) {
            return;
        }

        Mail::mailer(config('mail.newsletter_mailer'))
            ->to($this->email)
            ->send($emails->mailForSubscriber($site, $template, $newsletter));
    }
}
