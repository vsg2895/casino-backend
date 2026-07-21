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
 * Double opt-in for EVERY site: a new subscriber is stored as unverified
 * (pending) and only becomes verified when they click the emailed verify link.
 * The opt-out list config('mail.auto_verify_slugs') (empty by default) lets a
 * specific site skip the click and be auto-verified on subscribe.
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

        $isNewOrReactivated = $newsletter->wasRecentlyCreated || $resubscribed || $reactivated;

        if ($isNewOrReactivated) {
            // Double opt-in: a fresh/reactivated subscriber starts unverified
            // (pending) and must click the emailed link — unless this site is in
            // the auto-verify opt-out list. Only touched on this transition so a
            // still-active subscriber re-submitting keeps their verified state.
            $newsletter->forceFill(['verified' => $this->autoVerifies()])->save();
        }

        // Send the verify email for a new/reactivated subscriber, AND re-send it
        // to an existing subscriber who is still pending (unverified) so they can
        // finish confirming. A verified subscriber never reaches here (blocked at
        // validation) — and the guard keeps it that way defensively.
        if ($isNewOrReactivated || ! $newsletter->verified) {
            SendNewsletterWelcomeEmail::dispatch($this->siteId, $this->email);
        }
    }

    /** Trimmed name, or null when blank/omitted (the field is optional). */
    private function normalizedFullName(): ?string
    {
        $name = trim((string) $this->fullName);

        return $name === '' ? null : $name;
    }

    /**
     * Whether this site skips the verify-link step (auto-verify on subscribe).
     * Default false — every site is double opt-in unless its slug is opted out
     * via config('mail.auto_verify_slugs').
     */
    private function autoVerifies(): bool
    {
        $slug = Site::whereKey($this->siteId)->value('slug');

        return $slug !== null
            && in_array($slug, (array) config('mail.auto_verify_slugs', []), true);
    }
}
