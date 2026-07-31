<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PromotionMailerException;
use App\Models\EmailSchedule;
use App\Models\Newsletter;
use App\Models\PromotionEmailHistory;
use App\Models\Site;
use App\Models\Unsubscribe;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\PromotionEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the site's promotion template to ONE BATCH of recipients (≈100 emails).
 *
 * Efficiency (the whole point of batching):
 *  - Site + promotion template are loaded ONCE and reused for the batch.
 *  - Recipients are hydrated with their unsubscribe tokens in a SINGLE query
 *    (`whereIn` on the batch's emails) — no per-email lookups, no N+1. That same
 *    query re-excludes anyone who opted out since fan-out, so a last-second
 *    unsubscribe is still honored.
 *  - Only the email addresses travel in the job payload — never the tokens.
 *
 * Delivery transport is chosen per-schedule (see {@see PromotionMailerFactory}):
 * the .env SMTP mailer (default) OR a stored SendGrid key. Only the provider name
 * and the key ID travel in the payload — the decrypted key is resolved here at
 * send time, so a key disabled/deleted after fan-out fails the batch gracefully.
 *
 * Runs on the LOW queue (marketing).
 */
class SendPromotionBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'low';

    /** Retry the whole job once if it fails mid-batch (e.g. transient infra). */
    public int $tries = 2;

    /** Seconds to wait before that retry. */
    public int $backoff = 30;

    /** @param list<string> $emails */
    public function __construct(
        public readonly int $siteId,
        public readonly array $emails,
        public readonly string $provider = EmailSchedule::PROVIDER_SMTP,
        public readonly ?int $sendgridKeyId = null,
    ) {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(PromotionEmailService $promotions, PromotionMailerFactory $mailers): void
    {
        if ($this->emails === []) {
            return;
        }

        $site = Site::find($this->siteId);
        if ($site === null) {
            return;
        }

        $template = $site->promotionEmailOrDefault();

        if (! $template->active) {
            return;
        }

        // One query for the whole batch: fetch each address's promotion token and
        // drop anyone who has since opted out of the promotion stream.
        $recipients = Newsletter::query()
            ->where('site_id', $this->siteId)
            ->whereIn('email', $this->emails)
            ->whereNotExists(function (Builder $query): void {
                $query->from('unsubscribes')
                    ->whereColumn('unsubscribes.email', 'newsletters.email')
                    ->where('unsubscribes.site_id', $this->siteId)
                    ->where('unsubscribes.type', Unsubscribe::TYPE_PROMOTION);
            })
            ->get(['email', 'full_name', 'promotion_unsubscribe_token']);

        // Idempotency: one query against the history for who already received
        // this promotion within the last 24 hours (successes only), so neither
        // a job retry, a duplicate schedule, nor a cross-midnight re-run ever
        // re-sends a delivered address.
        $alreadySent = array_flip(
            PromotionEmailHistory::sentWithinDayAmong($this->siteId, $recipients->pluck('email')->all()),
        );

        // Resolve the transport for this schedule's saved provider: the .env SMTP
        // mailer (default) or a stored SendGrid key. If a SendGrid key was chosen
        // but has since been disabled/deleted, fail this batch GRACEFULLY — log
        // and stop, without crashing the worker or re-queuing forever.
        try {
            $resolved = $mailers->resolve($this->provider, $this->sendgridKeyId);
        } catch (PromotionMailerException $e) {
            Log::error('Promotion batch skipped: mail transport unavailable', [
                'site_id'         => $this->siteId,
                'provider'        => $this->provider,
                'sendgrid_key_id' => $this->sendgridKeyId,
                'batch_size'      => count($this->emails),
                'error'           => $e->getMessage(),
            ]);

            return;
        }

        $mailer = $resolved->mailer;
        $fromAddress = $resolved->fromAddress;

        // Outcome of every processed address (email => success|failed|skipped)
        // — collected for ONE bulk history insert at the end (never
        // per-recipient), so every attempt is recorded with its status.
        // $errors carries the failure message for the failed ones.
        $attempts = [];
        $errors = [];

        foreach ($recipients as $recipient) {
            $email = (string) $recipient->email;

            // Delivered within the last 24h — skip, and record the skip.
            if (isset($alreadySent[$email])) {
                $attempts[$email] = PromotionEmailHistory::STATUS_SKIPPED;

                continue;
            }
            try {
                // From = the authenticated .env mailbox with the template's
                // from_name as display name, so the SMTP server accepts it.
                $mailable = $promotions->mailFor($site, $template, $email, (string) $recipient->promotion_unsubscribe_token, $recipient->full_name)
                    ->usingFromAddress($fromAddress);
                $mailer->to($email)->send($mailable);
                $attempts[$email] = PromotionEmailHistory::STATUS_SUCCESS;
            } catch (Throwable $e) {
                // This address was attempted exactly once. Don't abort the batch
                // or fail the job for one bad recipient — log it, record the
                // failure, and keep going.
                Log::warning('Promotion send failed for a recipient', [
                    'site_id' => $this->siteId,
                    'email'   => $email,
                    'error'   => $e->getMessage(),
                ]);
                $attempts[$email] = PromotionEmailHistory::STATUS_FAILED;
                // Stored on the history row so the admin can see WHY it failed
                // without digging through the logs.
                $errors[$email] = $e->getMessage();
            }
        }

        $this->recordHistory($attempts, $errors);
    }

    /**
     * Append this batch's outcomes to the long-term history in a single bulk
     * insert. Best-effort and fully isolated: a history-write failure is logged
     * but never affects the (already completed) send flow or the job outcome.
     *
     * @param  array<string, string>       $attempts  email => STATUS_* value
     * @param  array<string, string|null>  $errors    email => failure message
     */
    private function recordHistory(array $attempts, array $errors = []): void
    {
        if ($attempts === []) {
            return;
        }

        try {
            PromotionEmailHistory::recordAttempts($this->siteId, $attempts, $errors);
        } catch (Throwable $e) {
            Log::warning('Promotion history write failed', [
                'site_id' => $this->siteId,
                'count'   => count($attempts),
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /** Called when the job ultimately fails (retries exhausted). */
    public function failed(Throwable $e): void
    {
        Log::error('Promotion batch job failed', [
            'site_id'     => $this->siteId,
            'batch_size'  => count($this->emails),
            'error'       => $e->getMessage(),
        ]);
    }
}
