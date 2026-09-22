<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\ForumAccountEmail;
use App\Models\ForumUser;
use App\Models\Site;
use App\Support\Mail\SiteSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers a forum confirm-address or reset-password email.
 *
 * ── HIGH queue, and a timeout well under retry_after ────────────────────────
 *
 * A human is waiting on both of these — one is mid-registration, the other is
 * locked out — so they go on `high` alongside the newsletter verify mail.
 *
 * The timeout is the platform rule that matters here: it must stay BELOW the
 * queue's `retry_after`, or a slow send gets handed to a second worker and the
 * member receives the same link twice. For a reset that is worse than cosmetic,
 * because two links in an inbox is exactly what a phishing attempt looks like.
 *
 * ── Unsubscribe status is deliberately NOT consulted ────────────────────────
 *
 * The newsletter jobs check `Unsubscribe::hasAny()` and stop. These two must
 * not: they are transactional, requested seconds ago by the recipient, and a
 * marketing opt-out cannot be allowed to lock somebody out of an account they
 * are actively trying to use. Different category of mail, different rule.
 */
class SendForumAccountEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'high';

    /** Below the queue's retry_after — see the class docblock. */
    public int $timeout = 30;

    public int $tries = 3;

    public function __construct(
        public readonly int $forumUserId,
        public readonly string $type,
        public readonly string $actionUrl,
        public readonly ?int $expiresInMinutes = null,
    ) {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(): void
    {
        $member = ForumUser::query()->find($this->forumUserId);

        // Deleted between request and send — nothing to do, and no error worth
        // raising.
        if ($member === null) {
            return;
        }

        $site = Site::find($member->site_id);

        if ($site === null) {
            return;
        }

        $mailable = new ForumAccountEmail(
            $site,
            $this->type,
            $member->display_name,
            $this->actionUrl,
            $this->expiresInMinutes,
        );

        /*
         * The From domain is resolved PER SITE.
         *
         * This is the rule that decides whether the mail arrives at all: SPF and
         * DKIM are published per domain, so a member who registered on winpalack
         * must receive mail from @winpalack. A shared From address sends without
         * error and silently lands in spam — the failure mode this project has
         * already been bitten by.
         */
        Mail::mailer(config('mail.public_mailer'))
            ->to($member->email)
            ->send($mailable->usingFromAddress(SiteSender::verificationAddress($site)));
    }
}
