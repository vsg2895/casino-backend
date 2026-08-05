<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PromotionMailerException;
use App\Models\EmailSchedule;
use App\Models\Newsletter;
use App\Models\PromotionEmailHistory;
use App\Models\Site;
use App\Models\SitePromotionEmail;
use App\Models\Unsubscribe;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\PromotionEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the site's promotion template to ONE BATCH of recipients.
 *
 * Efficiency (the whole point of batching):
 *  - Site + promotion template are loaded ONCE and reused for the batch.
 *  - Recipients are hydrated with their unsubscribe tokens in a SINGLE query
 *    (`whereIn` on the batch's emails) — no per-email lookups, no N+1. That same
 *    query re-excludes anyone who opted out since fan-out, so a last-second
 *    unsubscribe is still honored. Rows come back as plain objects rather than
 *    Eloquent models: nothing here needs a model, and hydration is pure cost.
 *  - Only the email addresses travel in the job payload — never the tokens.
 *
 * Delivery transport is chosen per-schedule (see {@see PromotionMailerFactory}):
 * the .env SMTP mailer (default) OR a stored SendGrid key. Only the provider name
 * and the key ID travel in the payload — the decrypted key is resolved here at
 * send time, so a key disabled/deleted after fan-out fails the batch gracefully.
 *
 * DUPLICATE SAFETY. Two mechanisms, because at 50k recipients a retry is not a
 * hypothetical:
 *  1. Before sending, every address already delivered to within 24h is skipped
 *     (one query against the history's UNIQUE key).
 *  2. Outcomes are flushed to that history DURING the batch, every
 *     `history_flush_size` attempts — not only at the end. A worker killed
 *     mid-batch (timeout, OOM, deploy) therefore leaves at most one flush
 *     window of delivered addresses unrecorded, instead of the whole batch.
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

    /** Fallback attempts buffered before a history flush. */
    private const int HISTORY_FLUSH_SIZE = 25;

    /** Retry the whole job once if it fails mid-batch (e.g. transient infra). */
    public int $tries = 2;

    /** Seconds to wait before that retry. */
    public int $backoff = 30;

    /**
     * Hard cap on a batch's runtime. Sending is sequential and network-bound, so
     * without this the 60s worker default kills long batches — and, with
     * `retry_after` at its default, hands the SAME batch to another worker while
     * the first is still sending. MUST stay below the connection's `retry_after`.
     */
    public int $timeout;

    /**
     * Credential row id for {@see $provider}, in that provider's own table.
     *
     * Declared as a plain defaulted property rather than a promoted readonly
     * one ON PURPOSE. Jobs already queued when this change deploys were
     * serialised without it; PHP restores a plain property to its declared
     * default when the key is absent from the payload, whereas an uninitialised
     * readonly property would throw on first access. In-flight campaigns
     * therefore keep running through the legacy $sendgridKeyId slot below.
     */
    public ?int $credentialId = null;

    /** @param list<string> $emails */
    public function __construct(
        public readonly int $siteId,
        public readonly array $emails,
        public readonly string $provider = EmailSchedule::PROVIDER_SMTP,
        /** @deprecated Superseded by $credentialId; kept so old payloads and callers keep working. */
        public readonly ?int $sendgridKeyId = null,
        ?int $credentialId = null,
    ) {
        $this->onQueue(self::ON_QUEUE);
        $this->timeout = (int) config('promotions.batch_timeout', 240);
        // A caller that still passes only $sendgridKeyId (or an old payload)
        // resolves exactly as before.
        $this->credentialId = $credentialId ?? $sendgridKeyId;
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

        $template = $this->template($site);

        if ($template === null || ! $template->active) {
            return;
        }

        // One query for the whole batch: fetch each address's promotion token and
        // drop anyone who has since opted out of the promotion stream. toBase()
        // keeps the soft-delete scope but skips model hydration for rows we only
        // read three scalars from.
        $recipients = Newsletter::query()
            ->where('site_id', $this->siteId)
            ->whereIn('email', $this->emails)
            ->whereNotExists(function (Builder $query): void {
                $query->from('unsubscribes')
                    ->whereColumn('unsubscribes.email', 'newsletters.email')
                    ->where('unsubscribes.site_id', $this->siteId)
                    ->where('unsubscribes.type', Unsubscribe::TYPE_PROMOTION);
            })
            ->toBase()
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
            $resolved = $mailers->resolve($this->provider, $this->credentialId ?? $this->sendgridKeyId);
        } catch (PromotionMailerException $e) {
            Log::error('Promotion batch skipped: mail transport unavailable', [
                'site_id'         => $this->siteId,
                'provider'        => $this->provider,
                'sendgrid_key_id' => $this->sendgridKeyId,
                'credential_id'   => $this->credentialId ?? $this->sendgridKeyId,
                'batch_size'      => count($this->emails),
                'error'           => $e->getMessage(),
            ]);

            return;
        }

        $mailer = $resolved->mailer;
        $fromAddress = $resolved->fromAddress;
        $flushEvery = $this->flushSize();

        // Outcome of every processed address (email => success|failed|skipped),
        // buffered for a BULK history insert — never one write per recipient.
        // $errors carries the failure message for the failed ones. Both are
        // flushed and reset every $flushEvery attempts, so neither grows with
        // the batch and a crash can only lose one window.
        $attempts = [];
        $errors = [];
        $sent = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            $email = (string) $recipient->email;

            // Delivered within the last 24h — skip, and record the skip.
            if (isset($alreadySent[$email])) {
                $attempts[$email] = PromotionEmailHistory::STATUS_SKIPPED;
            } else {
                try {
                    // From = the authenticated .env mailbox with the template's
                    // from_name as display name, so the SMTP server accepts it.
                    $mailable = $promotions->mailFor($site, $template, $email, (string) $recipient->promotion_unsubscribe_token, $recipient->full_name)
                        ->usingFromAddress($fromAddress);
                    $mailer->to($email)->send($mailable);
                    $attempts[$email] = PromotionEmailHistory::STATUS_SUCCESS;
                    $sent++;
                } catch (Throwable $e) {
                    // This address was attempted exactly once. Don't abort the
                    // batch or fail the job for one bad recipient — log it,
                    // record the failure, and keep going.
                    Log::warning('Promotion send failed for a recipient', [
                        'site_id' => $this->siteId,
                        'email'   => $email,
                        'error'   => $e->getMessage(),
                    ]);
                    $attempts[$email] = PromotionEmailHistory::STATUS_FAILED;
                    // Stored on the history row so the admin can see WHY it
                    // failed without digging through the logs.
                    $errors[$email] = $e->getMessage();
                    $failed++;
                }
            }

            // Checkpoint: everything decided so far is durable, so a retry after
            // a crash resumes rather than re-sending.
            if (count($attempts) >= $flushEvery) {
                $this->recordHistory($attempts, $errors);
                $attempts = [];
                $errors = [];
            }
        }

        // Release the batch's rows before the final write — nothing below reads
        // them, and the job may sit in a long-lived worker process.
        unset($recipients, $alreadySent);

        $this->recordHistory($attempts, $errors);

        // One line per batch (not per recipient): a 50k campaign produces ~500
        // log lines instead of 50,000.
        Log::info('Promotion batch processed', [
            'site_id' => $this->siteId,
            'sent'    => $sent,
            'failed'  => $failed,
            'total'   => count($this->emails),
        ]);

        $this->logProgressMilestone($sent);
    }

    /**
     * The site's promotion template.
     *
     * Read via the relation first so the common path is a pure SELECT: the
     * firstOrCreate fallback only runs for a site whose template has never been
     * touched, keeping a write (and a unique-key race between the batch jobs of
     * one campaign) out of the hot path.
     */
    private function template(Site $site): ?SitePromotionEmail
    {
        /** @var SitePromotionEmail|null $existing */
        $existing = $site->promotionEmail;

        if ($existing !== null) {
            return $existing;
        }

        /** @var SitePromotionEmail $created */
        $created = $site->promotionEmailOrDefault();

        return $created;
    }

    /**
     * Log one line every N successful sends for this site's campaign.
     *
     * A batch job only ever sees its own ~100 addresses, so the running total
     * lives in the cache and is incremented atomically — several workers send
     * concurrently, and each must see a true cumulative figure. The counter is
     * keyed per site and day, matching the 24h dedup window, and expires on its
     * own; nothing has to clean it up.
     *
     * Best-effort by design: a cache hiccup must never fail a batch whose emails
     * have already gone out.
     */
    private function logProgressMilestone(int $sent): void
    {
        $every = (int) config('promotions.progress_log_every', 500);

        if ($sent <= 0 || $every <= 0) {
            return;
        }

        try {
            $key = 'promotion-progress:' . $this->siteId . ':' . Carbon::now()->toDateString();

            // add() is atomic and, unlike a bare increment, seeds the TTL — so
            // the key cannot linger forever if a campaign is interrupted.
            Cache::add($key, 0, Carbon::now()->addDay());

            $total = (int) Cache::increment($key, $sent);
            $before = $total - $sent;

            // Only log when this batch pushed the total past a milestone.
            if (intdiv($total, $every) <= intdiv($before, $every)) {
                return;
            }

            Log::info('Promotion campaign progress', [
                'site_id'   => $this->siteId,
                'sent_total' => intdiv($total, $every) * $every,
                'message'   => sprintf('%d emails sent successfully', intdiv($total, $every) * $every),
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not record promotion progress', [
                'site_id' => $this->siteId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /** Attempts buffered before a history flush; always at least one. */
    private function flushSize(): int
    {
        $size = (int) config('promotions.history_flush_size', self::HISTORY_FLUSH_SIZE);

        return max(1, $size);
    }

    /**
     * Append these outcomes to the long-term history in a single bulk insert.
     * Best-effort and fully isolated: a history-write failure is logged but
     * never affects the (already completed) send flow or the job outcome.
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
