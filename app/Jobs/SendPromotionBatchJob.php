<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Newsletter;
use App\Models\PromotionEmailHistory;
use App\Models\Site;
use App\Models\Unsubscribe;
use App\Services\PromotionEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
 * Runs on the LOW queue (marketing) via the native SendGrid API mailer.
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
    ) {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(PromotionEmailService $promotions): void
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
            ->get(['email', 'promotion_unsubscribe_token']);

        // Idempotency: one query against the history for who already got today's
        // promotion, so a job retry after a mid-batch failure never re-sends a
        // delivered address.
        $alreadySent = array_flip(
            PromotionEmailHistory::sentTodayAmong($this->siteId, $recipients->pluck('email')->all()),
        );

        $mailer = Mail::mailer(config('mail.newsletter_mailer'));

        // Addresses actually delivered in this batch — collected for ONE bulk
        // history insert at the end (never per-recipient). A caught failure is
        // never added here, so failed addresses never reach the history.
        $delivered = [];

        foreach ($recipients as $recipient) {
            $email = (string) $recipient->email;

            // Already received today's promotion — skip (dedup / retry-safe).
            if (isset($alreadySent[$email])) {
                continue;
            }

            try {
                // From = the site's name (from_name) on the SendGrid-verified
                // address, so promotion mail delivers for every site.
                $mailable = $promotions->mailFor($site, $template, $email, (string) $recipient->promotion_unsubscribe_token);
                $mailable->fromAddressOverride = config('mail.newsletter_from_address');
                $mailer->to($email)->send($mailable);
                // Collected only after a successful send; written once, in bulk,
                // by recordHistory() at the end of the batch (never per-recipient).
                $delivered[] = $email;
            } catch (Throwable $e) {
                // This address was attempted once. Don't abort the batch or fail
                // the job for one bad recipient; it simply isn't marked as sent.
                Log::warning('Promotion send failed for a recipient', [
                    'site_id' => $this->siteId,
                    'email'   => $email,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->recordHistory($delivered);
    }

    /**
     * Append this batch's deliveries to the long-term history in a single bulk
     * insert. Best-effort and fully isolated: a history-write failure is logged
     * but never affects the (already completed) send flow or the job outcome.
     *
     * @param  list<string>  $delivered
     */
    private function recordHistory(array $delivered): void
    {
        if ($delivered === []) {
            return;
        }

        try {
            PromotionEmailHistory::recordMany($this->siteId, $delivered);
        } catch (Throwable $e) {
            Log::warning('Promotion history write failed', [
                'site_id' => $this->siteId,
                'count'   => count($delivered),
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
