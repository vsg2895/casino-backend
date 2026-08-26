<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\WarmupEmail;
use App\Models\WarmupSend;
use App\Models\WarmupSendRecipient;
use App\Services\Mail\WarmupMailResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends ONE BATCH of warmup addresses a rendered site template, and records the
 * per-address outcome.
 *
 * TRANSPORT IS UNCHANGED. Still pinned to config('warmup.mailer') — a literal
 * 'smtp' — so warmup keeps going out over the credentials in MAIL_HOST /
 * MAIL_USERNAME / MAIL_PASSWORD. Routing warmup through a per-site or
 * per-schedule provider would warm that provider's shared infrastructure instead
 * of this mailbox and silently defeat the feature. The From address still comes
 * from config('mail.from.address').
 *
 * A single bad address never stops the batch — it is logged, recorded as a failed
 * history row, and the loop continues, matching how promotion batches behave.
 *
 * TWO WRITES PER FLUSH, and the difference between them matters:
 *
 *  - every attempt, delivered or not, becomes a {@see WarmupSendRecipient} row.
 *    That is the audit: it answers which address, which site, which template, when.
 *  - only DELIVERED addresses advance `warmup_emails.last_sent_at`. That column is
 *    what the cooldown filter reads, so a permanently broken address stays eligible
 *    for the next run instead of serving an N-day cooldown it never earned.
 *
 * Both happen in the same buffered flush, so the two can only ever disagree by
 * whatever was in flight when a worker was killed.
 *
 * Runs on the LOW queue, like the fan-out that dispatched it.
 */
class SendWarmupBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'low';

    /** One retry for transient infrastructure, as promotion batches use. */
    public int $tries = 2;

    public int $backoff = 30;

    /** Fallback when config is unavailable. */
    private const int HISTORY_FLUSH_SIZE = 25;

    /** Sending is sequential and network-bound; must stay below `retry_after`. */
    public int $timeout;

    /** @param list<string> $emails */
    public function __construct(
        public readonly array $emails,
        public readonly int $siteId,
        public readonly string $template,
        public readonly int $warmupSendId,
    ) {
        $this->onQueue(self::ON_QUEUE);
        $this->timeout = (int) config('warmup.send_timeout', 240);
    }

    public function handle(WarmupMailResolver $resolver): void
    {
        if ($this->emails === []) {
            return;
        }

        // A queue worker is a long-lived process: anything retained here outlives
        // the job. If the query log is on — a debug package, APP_DEBUG tooling, an
        // earlier job that enabled it — every statement is kept in memory for the
        // worker's whole life. Cheap insurance rather than a fix for a known leak.
        DB::connection()->disableQueryLog();

        // The run may have been stopped after this batch was queued. Checked
        // here rather than only at dispatch time, because a queue can hold work
        // for far longer than the decision to stop takes.
        if (WarmupSend::isCancelled($this->warmupSendId)) {
            Log::info('Warmup batch skipped: the run was cancelled', [
                'warmup_send_id' => $this->warmupSendId,
                'batch_size'     => count($this->emails),
            ]);

            return;
        }

        $site = Site::find($this->siteId);

        if ($site === null || ! WarmupMailResolver::supports($this->template)) {
            Log::warning('Warmup batch skipped: site missing or template not permitted', [
                'site_id'    => $this->siteId,
                'template'   => $this->template,
                'batch_size' => count($this->emails),
            ]);

            return;
        }

        // Resolved HERE rather than carried in the payload: an address can be
        // deleted between fan-out and delivery, and a stale id would make the
        // history INSERT violate its foreign key and lose the whole buffer. A
        // missing address simply yields a null id — the denormalised `email`
        // column keeps the row readable either way. One indexed query per batch.
        $ids = $this->resolveIds();

        // Pinned to SMTP by config, never to whatever admin_mailer happens to be.
        $mailer = Mail::mailer(config('warmup.mailer', 'smtp'));
        $fromAddress = config('mail.from.address') ?: null;

        $flushSize = $this->flushSize();
        $sent = 0;
        $failed = 0;

        /** @var list<array<string, mixed>> $buffer */
        $buffer = [];

        foreach ($this->emails as $email) {
            $email = (string) $email;
            $error = null;
            $mailable = null;

            try {
                $mailable = $resolver->build($this->template, $site, $email)
                    ->usingFromAddress($fromAddress);

                $mailer->to($email)->send($mailable);
                $sent++;
            } catch (Throwable $e) {
                // One unroutable address must not abort the rest of the batch.
                $failed++;
                $error = $e->getMessage();

                Log::warning('Warmup send failed for a recipient', [
                    'email' => $email,
                    'error' => $error,
                ]);
            } finally {
                // Drop the rendered message before building the next one. Each
                // mailable holds a fully rendered HTML body — tens of kilobytes
                // for these templates — and without this the previous recipient's
                // copy stays reachable for the whole of the next iteration, so
                // peak memory carries two bodies instead of one. In `finally` so a
                // failed send releases it too.
                unset($mailable);
            }

            $buffer[] = $this->row($email, $ids[$email] ?? null, $error);

            if (count($buffer) >= $flushSize) {
                $this->flush($buffer);
                $buffer = [];
            }
        }

        $this->flush($buffer);

        // Release what the loop no longer needs before the batch returns. A queue
        // worker keeps this process alive across many jobs, so anything still
        // referenced here is memory the NEXT batch starts with.
        unset($buffer, $ids, $mailer);
        $resolver->flushTemplates();

        // One line per batch, not per recipient.
        Log::info('Warmup batch processed', [
            'warmup_send_id' => $this->warmupSendId,
            'site_id'        => $site->id,
            'template'       => $this->template,
            'sent'           => $sent,
            'failed'         => $failed,
            'total'          => count($this->emails),
        ]);
    }

    /**
     * Address → id for the addresses still on the list.
     *
     * @return array<string, int>
     */
    private function resolveIds(): array
    {
        try {
            return WarmupEmail::query()
                ->whereIn('email', $this->emails)
                ->pluck('id', 'email')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        } catch (Throwable $e) {
            // Never block a send over the audit's convenience column. Rows are
            // still written, just without the link back to the list row.
            Log::warning('Could not resolve warmup address ids; history rows will be unlinked', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /** One buffered history row. @return array<string, mixed> */
    private function row(string $email, ?int $warmupEmailId, ?string $error): array
    {
        $now = Carbon::now();

        return [
            'warmup_send_id'  => $this->warmupSendId,
            'warmup_email_id' => $warmupEmailId,
            'site_id'         => $this->siteId,
            'email'           => $email,
            'template'        => $this->template,
            'status'          => $error === null
                ? WarmupSendRecipient::STATUS_SENT
                : WarmupSendRecipient::STATUS_FAILED,
            // Truncated: a transport can return a multi-kilobyte SMTP transcript,
            // and the admin only ever reads the first line of it.
            'error'      => $error === null ? null : mb_substr($error, 0, 1000),
            'sent_at'    => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Write the buffered history rows and advance the cooldown for the delivered
     * ones, in that order.
     *
     * History first on purpose: if the second write fails, the audit still shows
     * the send happened and the address is merely eligible again sooner. The
     * reverse ordering would lose the evidence and keep the cooldown.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function flush(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        try {
            DB::table((new WarmupSendRecipient())->getTable())->insert($rows);
        } catch (Throwable $e) {
            // The mail is already out. Losing an audit row must never fail — and
            // never retry — a batch that has physically sent.
            Log::warning('Could not write warmup history rows', [
                'warmup_send_id' => $this->warmupSendId,
                'rows'           => count($rows),
                'error'          => $e->getMessage(),
            ]);
        }

        $delivered = [];
        $latest = null;

        foreach ($rows as $row) {
            if ($row['status'] !== WarmupSendRecipient::STATUS_SENT) {
                continue;
            }

            $delivered[] = $row['email'];

            if ($latest === null || $row['sent_at']->greaterThan($latest)) {
                $latest = $row['sent_at'];
            }
        }

        $this->markContacted($delivered, $latest);

        // Mailables and their Symfony Email graphs contain reference cycles that
        // refcounting alone cannot reclaim, so they sit in the GC root buffer
        // until PHP decides to collect. Running it once per flush (every 25
        // recipients by default) bounds how many accumulate, at a cost that is
        // negligible beside 25 SMTP round-trips.
        gc_collect_cycles();
    }

    /**
     * @param  list<string>  $emails
     *
     * One UPDATE stamps the whole buffer with the newest attempt time in it, so
     * `last_sent_at` is accurate to within one flush — seconds — against a filter
     * whose smallest unit is a day.
     */
    private function markContacted(array $emails, ?Carbon $at): void
    {
        if ($emails === []) {
            return;
        }

        try {
            WarmupEmail::markContacted($emails, $at);
        } catch (Throwable $e) {
            // The mail is already out; losing the stamp only means these addresses
            // become eligible again sooner. Never fail a sent batch here.
            Log::warning('Could not stamp warmup cooldown timestamps', [
                'addresses' => count($emails),
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function flushSize(): int
    {
        $size = (int) config('warmup.history_flush_size', self::HISTORY_FLUSH_SIZE);

        return $size > 0 ? $size : self::HISTORY_FLUSH_SIZE;
    }

    public function failed(Throwable $e): void
    {
        Log::error('Warmup batch job failed', [
            'warmup_send_id' => $this->warmupSendId,
            'site_id'        => $this->siteId,
            'template'       => $this->template,
            'batch_size'     => count($this->emails),
            'error'          => $e->getMessage(),
        ]);
    }
}
