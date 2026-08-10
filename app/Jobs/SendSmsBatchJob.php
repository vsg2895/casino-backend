<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NewsletterBasedOnPhone;
use App\Models\PhoneSmsHistory;
use App\Models\TwilioConfig;
use App\Services\Sms\SmsSendResult;
use App\Services\Sms\TwilioSmsClient;
use App\Support\Phone\PhoneNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the message to ONE BATCH of phone numbers through a stored Twilio
 * credential, and records the outcome of every single one.
 *
 * Only the credential's row id travels in the payload — the auth token is
 * decrypted inside {@see TwilioSmsClient} at send time, so a queued job never
 * carries a secret. Same rule the promotion batch jobs follow.
 *
 * A single bad number never stops the batch. Twilio answers an unroutable or
 * opted-out recipient with a 400 and a numeric code; that is per-recipient
 * information, so it becomes a `failed` history row and the loop continues. Only
 * a run-wide fault (bad credentials, no sender) aborts, because there the next
 * recipient would fail identically.
 *
 * NOT retried ($tries = 1), and this differs deliberately from the promotion
 * email batch job, which allows one retry. An email batch is protected by the 24h
 * delivery-history dedup, so a retry re-sends to nobody; there is no such guard
 * here, so a retry would re-message every number the first attempt had already
 * reached — and a duplicate SMS is billed and cannot be recalled. The transient
 * failures a retry would rescue are already handled per recipient: a timeout on
 * one number is recorded and the batch moves on, leaving the admin an exact list
 * of what to re-send.
 *
 * Runs on the LOW queue, like the fan-out that dispatched it.
 */
class SendSmsBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'low';

    /** One shot: see the class docblock on why a retry would double-charge. */
    public int $tries = 1;

    /** Sending is sequential and network-bound; must stay below `retry_after`. */
    public int $timeout;

    /** Fallback history rows buffered before a write; see config/sms.php. */
    private const int HISTORY_FLUSH_SIZE = 25;

    /**
     * Seconds of the timeout budget reserved for the final history flush, so the
     * loop always stops with time left to persist what it already sent.
     */
    private const int TIMEOUT_SAFETY_MARGIN = 20;

    /** Buffered history rows, flushed in groups rather than one INSERT each. */
    private array $pendingHistory = [];

    /**
     * @param  list<string>  $phones  Already E.164-normalised — they came off the
     *                                list, which only stores normalised numbers.
     */
    public function __construct(
        public readonly array $phones,
        public readonly string $body,
        public readonly int $twilioConfigId,
    ) {
        $this->onQueue(self::ON_QUEUE);
        $this->timeout = (int) config('sms.batch_timeout', 240);
    }

    public function handle(TwilioSmsClient $client): void
    {
        if ($this->phones === []) {
            return;
        }

        $config = TwilioConfig::find($this->twilioConfigId);

        if ($config === null || ! $config->isActive()) {
            // Deleted or disabled between fan-out and execution. Nothing is sent
            // and nothing is recorded — there is no attempt to record.
            Log::warning('SMS batch skipped: the Twilio configuration is missing or inactive', [
                'twilio_config_id' => $this->twilioConfigId,
                'batch_size'       => count($this->phones),
            ]);

            return;
        }

        $flushSize = max(1, (int) config('sms.history_flush_size', self::HISTORY_FLUSH_SIZE));
        $logEvery = max(1, (int) config('sms.progress_log_every', 500));

        // Stop voluntarily before the queue worker's alarm fires.
        //
        // Laravel enforces $timeout with pcntl_alarm, whose handler exits the
        // process outright — `finally` does not reliably run, so a batch killed
        // mid-loop would have really sent up to `flushSize` messages and recorded
        // none of them. That is the one outcome an admin cannot recover from,
        // because the history is the only record of who was reached.
        //
        // The pathological case is real: `send_batch_size` numbers each allowed
        // `request_timeout` seconds far exceeds `batch_timeout` if Twilio stalls
        // on every call. Ending early leaves the tail unsent — visibly, in the log
        // below — which is strictly better than sending them untracked.
        $deadline = microtime(true) + max(1, $this->timeout - self::TIMEOUT_SAFETY_MARGIN);

        $sent = 0;
        $failed = 0;
        $processed = 0;
        $abandoned = 0;

        try {
            foreach ($this->phones as $phone) {
                if (microtime(true) >= $deadline) {
                    $abandoned = count($this->phones) - $processed;

                    break;
                }

                $result = $this->sendOne($client, $config, (string) $phone);

                if ($result->ok) {
                    $sent++;
                } else {
                    $failed++;
                }

                $this->recordAttempt((string) $phone, $result);
                $processed++;

                if (count($this->pendingHistory) >= $flushSize) {
                    $this->flushHistory();
                }

                if ($processed % $logEvery === 0) {
                    Log::info('SMS batch progress', [
                        'twilio_config_id' => $config->id,
                        'processed'        => $processed,
                        'sent'             => $sent,
                        'failed'           => $failed,
                    ]);
                }
            }
        } finally {
            // Always persist what was already attempted. Without this, a batch
            // that aborted part-way would have sent real messages and recorded
            // none of them — the one outcome that leaves an admin unable to tell
            // who was reached.
            $this->flushHistory();
        }

        // One line per batch, not per recipient.
        Log::info('SMS batch processed', [
            'twilio_config_id' => $config->id,
            'sent'             => $sent,
            'failed'           => $failed,
            'abandoned'        => $abandoned,
            'total'            => count($this->phones),
        ]);

        if ($abandoned > 0) {
            // Loud, and separate from the line above: these numbers were never
            // contacted and — with $tries = 1 — never will be by this run. An
            // operator has to know to re-send to them.
            Log::error('SMS batch ran out of time; the remaining numbers were NOT sent', [
                'twilio_config_id' => $config->id,
                'abandoned'        => $abandoned,
                'processed'        => $processed,
                'batch_timeout'    => $this->timeout,
            ]);
        }
    }

    /**
     * Send to one number, converting a run-wide fault into a thrown exception and
     * anything per-recipient into a result.
     */
    private function sendOne(TwilioSmsClient $client, TwilioConfig $config, string $phone): SmsSendResult
    {
        $result = $client->send($config, $phone, $this->body);

        if ($result->isOptOut()) {
            $this->markOptedOut($phone);
        }

        return $result;
    }

    /**
     * Flag a number that Twilio reports as opted out, so later runs skip it.
     *
     * The one failure that changes stored state rather than only being recorded —
     * continuing to message a STOP'd number is a compliance problem, not just a
     * wasted send. Failure to flag is logged but never fatal: the message has
     * already been rejected, and losing the batch over a bookkeeping write would
     * be worse.
     */
    private function markOptedOut(string $phone): void
    {
        try {
            NewsletterBasedOnPhone::query()
                ->where('phone', $phone)
                ->where('opted_out', false)
                ->update([
                    'opted_out'    => true,
                    'opted_out_at' => Carbon::now(),
                    'updated_at'   => Carbon::now(),
                ]);
        } catch (Throwable $e) {
            Log::warning('Could not flag an opted-out number', [
                'phone' => PhoneNumber::mask($phone),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Buffer one attempt's outcome as a history row. */
    private function recordAttempt(string $phone, SmsSendResult $result): void
    {
        $now = Carbon::now();

        $this->pendingHistory[] = [
            'phone'            => $phone,
            'twilio_config_id' => $this->twilioConfigId,
            'message_sid'      => $result->messageSid,
            'status'           => $result->status(),
            'error_code'       => $result->errorCode,
            // Keep only the diagnostic head; a proxy's HTML error page would
            // otherwise fill the column.
            'error'            => $result->error === null
                ? null
                : mb_strimwidth($result->error, 0, 500, '…'),
            'body'             => $this->body,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
    }

    /** Write the buffered history rows in one INSERT and clear the buffer. */
    private function flushHistory(): void
    {
        if ($this->pendingHistory === []) {
            return;
        }

        $rows = $this->pendingHistory;
        // Cleared BEFORE the write: if the insert throws, the finally-block flush
        // must not retry the same rows and risk duplicating them.
        $this->pendingHistory = [];

        try {
            DB::table((new PhoneSmsHistory())->getTable())->insert($rows);
        } catch (Throwable $e) {
            // The messages were really sent; losing the audit is bad but must not
            // take the batch down. Log enough to reconstruct it by hand.
            Log::error('Could not write SMS history rows', [
                'rows'  => count($rows),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('SMS batch job failed', [
            'twilio_config_id' => $this->twilioConfigId,
            'batch_size'       => count($this->phones),
            'error'            => $e->getMessage(),
        ]);
    }
}
