<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\WarmupEmail;
use App\Models\WarmupSend;
use App\Models\WarmupSendRecipient;
use App\Services\Mail\RateLimitDecision;
use App\Services\Mail\WarmupMailResolver;
use App\Services\Mail\WarmupRateLimiter;
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
 * RATE LIMITED. Every send waits for a slot in the shared rolling window first
 * (see {@see WarmupRateLimiter}) — at most `warmup.rate_limit.max_emails` in any
 * `window_seconds` stretch, counted across all workers, not per batch and not
 * per process. The limiter sits immediately before the send attempt and nowhere
 * else, so retries, failures and every other path through this loop are bound by
 * it too.
 *
 * THAT MAKES A BATCH SLOW, which changes one thing about this job's lifetime: 100
 * addresses at ten a minute need roughly ten minutes, and `send_timeout` is 240
 * seconds. A job killed by its timeout is retried, and this job's retry re-sends
 * its WHOLE payload — so the limit would have bought deliverability at the price
 * of duplicate mail. Instead the loop watches a time budget derived from that
 * timeout and, when it is nearly spent, re-queues the addresses it has not
 * reached as another batch of the same run. Same class, same queue, same run id;
 * no new retry mechanism, and no address is attempted twice.
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

    /**
     * Seconds held back from the time budget, on top of one rate-limit window.
     *
     * The budget is tested BEFORE a send, and that send may then wait up to a
     * whole window for its slot; this covers the final history flush and the
     * re-queue after it, so an invocation that decides to continue still returns
     * before the queue kills it.
     */
    private const int BUDGET_MARGIN_SECONDS = 20;

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

    public function handle(WarmupMailResolver $resolver, WarmupRateLimiter $limiter): void
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
        $attempted = 0;

        // Wall-clock budget for THIS invocation, not for the batch: whatever is
        // left over when it runs out goes back on the queue. Measured from the
        // same Carbon clock the limiter uses, so a test that fakes time fakes
        // both consistently.
        $budget = $this->timeBudgetSeconds($limiter);
        $startedAtMs = (int) Carbon::now()->getPreciseTimestamp(3);

        /** @var list<string> $deferred Addresses handed to a follow-up batch. */
        $deferred = [];
        $cancelled = false;

        /** @var list<array<string, mixed>> $buffer */
        $buffer = [];

        foreach ($this->emails as $index => $email) {
            // Skipped on the first address, so every invocation attempts at least
            // one send. Without that, a batch could hand itself straight back to
            // the queue forever and the run would never advance.
            if ($attempted > 0 && $this->budgetSpent($startedAtMs, $budget)) {
                // Everything from here on, INCLUDING this address: nothing was
                // attempted for it and no slot was taken for it.
                $deferred = array_values(array_slice($this->emails, (int) $index));

                break;
            }

            // THE RATE LIMIT. Immediately before the attempt and nowhere else, so
            // there is exactly one place in the send path that can consume a slot
            // and no path around it. Blocks until the rolling window has room.
            $decision = $limiter->acquire(
                function (RateLimitDecision $refused, float $wait): void {
                    Log::info('[Warmup] Rate limit reached. Waiting '.$this->seconds($wait).' seconds.', [
                        'warmup_send_id' => $this->warmupSendId,
                        'rate_window'    => $refused->occupancy(),
                        'wait_seconds'   => round($wait, 2),
                    ]);
                },
            );

            // Cancellation, checked AFTER the wait rather than before it.
            //
            // A rate-limited batch spends nearly all of its time waiting, so the
            // stop an operator issues almost always arrives DURING a wait — and
            // the message that wait was for is then the one that must not go out.
            // The slot the limiter just granted is forfeited, which is the right
            // way round: a wasted slot costs nothing, one more message after
            // "stop" is exactly what the button is for.
            //
            // The check at the top of this method still covers a batch that was
            // already cancelled when the worker picked it up.
            if (WarmupSend::isCancelled($this->warmupSendId)) {
                $cancelled = true;

                break;
            }

            $attempted++;
            $email = (string) $email;
            $error = null;
            $mailable = null;

            try {
                $mailable = $resolver->build($this->template, $site, $email)
                    ->usingFromAddress($fromAddress);

                $mailer->to($email)->send($mailable);
                $sent++;

                // Debug, not info: at the configured rate this is one line every
                // few seconds for the length of a run, and the batch summary
                // below is what an operator reads normally. No address here —
                // the window occupancy is the operational fact, and the failure
                // warning below is the one place an address is worth naming.
                Log::debug('[Warmup] Email sent. Rate window: '.$decision->occupancy().'.', [
                    'warmup_send_id' => $this->warmupSendId,
                ]);
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

        // History before the hand-off, always: the follow-up batch must never be
        // in flight while the attempts that preceded it are still only in memory.
        $this->flush($buffer);

        // Release what the loop no longer needs before the batch returns. A queue
        // worker keeps this process alive across many jobs, so anything still
        // referenced here is memory the NEXT batch starts with.
        unset($buffer, $ids, $mailer);
        $resolver->flushTemplates();

        if ($cancelled) {
            Log::info('[Warmup] Chunk stopped: the run was cancelled', [
                'warmup_send_id' => $this->warmupSendId,
                'sent'           => $sent,
                'failed'         => $failed,
                'not_attempted'  => count($this->emails) - $attempted,
            ]);

            return;
        }

        // One line per batch, not per recipient.
        Log::info('[Warmup] Chunk completed.', [
            'warmup_send_id' => $this->warmupSendId,
            'site_id'        => $site->id,
            'template'       => $this->template,
            'sent'           => $sent,
            'failed'         => $failed,
            'total'          => count($this->emails),
            'attempted'      => $attempted,
            'deferred'       => count($deferred),
            'rate_window'    => $limiter->max().' per '.$limiter->windowSeconds().'s',
        ]);

        if ($deferred !== []) {
            $this->requeue($deferred);

            // Deliberately no completion line here: the run is not finished, it is
            // continuing in another job.
            return;
        }

        $this->logRunCompletion();
    }

    /**
     * Hand the addresses this invocation did not reach back to the queue.
     *
     * The SAME job class, queue and run id — this is a continuation, not a retry
     * and not a new mechanism. `queued_count` on the run is untouched, because
     * these addresses were already counted when the fan-out queued them.
     *
     * @param  list<string>  $emails
     */
    private function requeue(array $emails): void
    {
        self::dispatch($emails, $this->siteId, $this->template, $this->warmupSendId);

        Log::info('[Warmup] Time budget reached; remaining addresses re-queued.', [
            'warmup_send_id' => $this->warmupSendId,
            'requeued'       => count($emails),
        ]);
    }

    /**
     * Seconds this invocation may spend before deferring the rest.
     *
     * Derived from the job's own timeout rather than configured separately, so the
     * two can never drift into the combination that kills a batch mid-send.
     */
    private function timeBudgetSeconds(WarmupRateLimiter $limiter): float
    {
        $reserve = $limiter->windowSeconds() + self::BUDGET_MARGIN_SECONDS;

        // At least one second, so a nonsensically small timeout still sends the
        // one address every invocation is guaranteed to attempt.
        return max((float) $this->timeout - $reserve, 1.0);
    }

    /**
     * Milliseconds, from the same clock the limiter uses — so faking time in a
     * test moves the window and the budget together.
     */
    private function budgetSpent(int $startedAtMs, float $budget): bool
    {
        $elapsed = ((int) Carbon::now()->getPreciseTimestamp(3) - $startedAtMs) / 1000;

        return $elapsed >= $budget;
    }

    /** A wait rendered for a log line: "6" and "0.4", never "6.0000001". */
    private function seconds(float $wait): string
    {
        return rtrim(rtrim(number_format($wait, 1, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Log once when the LAST batch of a run finishes.
     *
     * There is no completion hook to hang this on: the fan-out returns long before
     * the batches it queued, and the batches do not know about each other. The
     * count of recorded attempts reaching the run's `queued_count` is the one
     * signal available, and it is exact — every attempt writes a history row,
     * delivered or not.
     *
     * Best-effort by design. `queued_count` is written when the fan-out finishes,
     * so a run whose batches all complete before that (an inline `sync` queue, for
     * instance) simply gets no line, and a retried batch can push the count past
     * the total — hence `>=`. Never allowed to fail a batch that has sent.
     */
    private function logRunCompletion(): void
    {
        try {
            $queued = (int) (WarmupSend::query()->whereKey($this->warmupSendId)->value('queued_count') ?? 0);

            if ($queued <= 0) {
                return;
            }

            $recorded = WarmupSendRecipient::query()
                ->where('warmup_send_id', $this->warmupSendId)
                ->count();

            if ($recorded < $queued) {
                return;
            }

            Log::info('[Warmup] Warmup completed.', [
                'warmup_send_id' => $this->warmupSendId,
                'attempts'       => $recorded,
                'queued'         => $queued,
            ]);
        } catch (Throwable $e) {
            Log::warning('[Warmup] Could not determine whether the run finished', [
                'warmup_send_id' => $this->warmupSendId,
                'error'          => $e->getMessage(),
            ]);
        }
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
