<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Newsletter;
use App\Models\Site;
use App\Models\Unsubscribe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Persists a newsletter subscription for the given site and, when the email is
 * brand new for that site, queues the double opt-in verify email.
 *
 * Double opt-in: sites listed in config('mail.verify_required_slugs') (e.g.
 * winpalack) start unverified and become verified only when the emailed link is
 * clicked; every other site is auto-verified on subscribe (its verify email
 * still sends, and the link still lands on the congrats page).
 *
 * This is the "first" subscription job and runs on the HIGH-priority queue
 * (worker: --queue=high,low). The (site_id, email) unique index makes
 * firstOrCreate idempotent, so retries/duplicate submits are safe.
 */
class ProcessNewsletterSubscription implements ShouldQueue
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
        public readonly ?string $fullName = null,
    ) {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(): void
    {
        $fullName = $this->normalizedFullName();

        // Include soft-deleted rows: the (site_id, email) unique index still
        // covers them, so a re-subscribe must restore the trashed row rather
        // than INSERT a duplicate.
        $newsletter = Newsletter::withTrashed()->firstOrCreate(
            ['site_id' => $this->siteId, 'email' => $this->email],
            ['full_name' => $fullName],
        );

        $resubscribed = $newsletter->trashed();
        if ($resubscribed) {
            $newsletter->restore();
        }

        // Keep the name fresh when a returning subscriber supplies one.
        if ($fullName !== null && $newsletter->full_name !== $fullName) {
            $newsletter->full_name = $fullName;
            $newsletter->save();
        }

        // Re-consent: subscribing again clears any prior subscription-stream
        // opt-out so the welcome (and future subscription mail) can flow again.
        $reactivated = Unsubscribe::where('site_id', $this->siteId)
            ->where('email', $this->email)
            ->where('type', Unsubscribe::TYPE_SUBSCRIPTION)
            ->delete() > 0;

        // Verify genuinely new, re-subscribed (was trashed) OR reactivated emails.
        if ($newsletter->wasRecentlyCreated || $resubscribed || $reactivated) {
            // Sites that require the click start (or reset to) unverified; all
            // others are auto-verified on subscribe. Only touched here so a
            // still-active subscriber re-submitting keeps their verified state.
            $newsletter->forceFill(['verified' => ! $this->requiresVerification()])->save();

            SendNewsletterWelcomeEmail::dispatch($this->siteId, $this->email);
        }
    }

    /** Trimmed name, or null when blank/omitted (the field is optional). */
    private function normalizedFullName(): ?string
    {
        $name = trim((string) $this->fullName);

        return $name === '' ? null : $name;
    }

    /** Whether this site gates verification behind the emailed link. */
    private function requiresVerification(): bool
    {
        $slug = Site::whereKey($this->siteId)->value('slug');

        return $slug !== null
            && in_array($slug, (array) config('mail.verify_required_slugs', []), true);
    }
}
