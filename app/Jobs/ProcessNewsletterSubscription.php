<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Newsletter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Persists a newsletter subscription for the given site and, when the email is
 * brand new for that site, queues the confirmation email.
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
    ) {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(): void
    {
        // Include soft-deleted rows: the (site_id, email) unique index still
        // covers them, so a re-subscribe must restore the trashed row rather
        // than INSERT a duplicate.
        $newsletter = Newsletter::withTrashed()->firstOrCreate([
            'site_id' => $this->siteId,
            'email'   => $this->email,
        ]);

        $resubscribed = $newsletter->trashed();
        if ($resubscribed) {
            $newsletter->restore();
        }

        // Confirm genuinely new AND re-activated (previously unsubscribed) emails.
        if ($newsletter->wasRecentlyCreated || $resubscribed) {
            SendNewsletterWelcomeEmail::dispatch($this->siteId, $this->email);
        }
    }
}
