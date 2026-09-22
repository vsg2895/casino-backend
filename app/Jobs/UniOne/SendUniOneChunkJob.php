<?php

declare(strict_types=1);

namespace App\Jobs\UniOne;

use App\Models\UniOne\UniOneApiKey;
use App\Models\UniOne\UniOneReceiver;
use App\Models\UniOne\UniOneSend;
use App\Models\UniOne\UniOneSendChunk;
use App\Models\UniOne\UniOneSendRecipient;
use App\Services\UniOne\UniOneClient;
use App\Services\UniOne\UniOneOutcomeService;
use App\Support\UniOne\UniOneResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends ONE chunk of at most 500 recipients.
 *
 * ── The duplicate guard is `committed_at`, not `idempotence_key` ────────────
 *
 * UniOne honours `idempotence_key` for ONE MINUTE. This queue's `retry_after` is
 * 1200 s. Those windows do not overlap, so a normal retry arrives long after the
 * key expired and UniOne would treat it as a brand-new send.
 *
 * Therefore the first thing this job does is re-read its chunk row and return if
 * `committed_at` is set. That flag is written in the SAME TRANSACTION that
 * stamps the recipients, so there is no state in which the recipients were
 * updated and the chunk was not, or vice versa.
 *
 * The idempotence key still goes on every request — it costs nothing and it does
 * close the one window it can: two workers grabbing the same job inside a minute.
 *
 * ── Retry policy ────────────────────────────────────────────────────────────
 *
 * 429 and 5xx only, with jittered exponential backoff. 400/401/403/413 fail the
 * job immediately: they describe a defect in the request or the credentials, and
 * retrying a 413 would resend the same oversized body.
 *
 * `$timeout` sits well under retry_after (1200 s) so a slow request can never be
 * handed to a second worker while the first is still in flight.
 */
class SendUniOneChunkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Nothing runs on `default` on this platform — see deploy/supervisor. */
    public const string ON_QUEUE = 'low';

    public int $tries = 4;

    /** Below the queue's retry_after of 1200s. */
    public int $timeout = 120;

    /**
     * @param  list<int>  $receiverIds
     */
    public function __construct(
        public readonly int $sendId,
        public readonly int $chunkIndex,
        public readonly array $receiverIds,
    ) {
        $this->onQueue(self::ON_QUEUE);
    }

    /**
     * Jittered exponential backoff.
     *
     * Jitter matters when a send fans out: without it, twenty chunks rejected by
     * one 429 all retry at the same instant and reproduce the burst that caused
     * it.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [
            30 + random_int(0, 15),
            90 + random_int(0, 30),
            240 + random_int(0, 60),
        ];
    }

    public function handle(UniOneOutcomeService $outcomes): void
    {
        $send = UniOneSend::query()->find($this->sendId);

        if ($send === null || $send->status === UniOneSend::STATUS_CANCELLED) {
            return;
        }

        $chunk = UniOneSendChunk::query()
            ->where('unione_send_id', $this->sendId)
            ->where('chunk_index', $this->chunkIndex)
            ->first();

        if ($chunk === null) {
            return;
        }

        // THE GUARD. A retry of an already-delivered chunk stops here.
        if ($chunk->isCommitted()) {
            Log::info('UniOne chunk already committed — retry suppressed', [
                'send_id' => $this->sendId,
                'chunk'   => $this->chunkIndex,
            ]);

            return;
        }

        $key = UniOneApiKey::query()->find($send->unione_api_key_id);

        if ($key === null || ! $key->is_active) {
            $this->failChunk($chunk, $send, 'The API key is missing or inactive.');

            return;
        }

        $client = new UniOneClient($key);

        /*
         * A 429 on ANY worker quietens all of them. Releasing rather than
         * sleeping frees this worker for other queues and costs no attempt.
         */
        if ($client->isCoolingDown()) {
            $this->release(max(5, $client->cooldownSecondsRemaining()));

            return;
        }

        $receivers = UniOneReceiver::query()
            ->whereIn('id', $this->receiverIds)
            // Re-checked at send time, not just at selection: an address
            // suppressed by a webhook between queueing and sending must not be
            // mailed. The scope is the only gate, here as everywhere.
            ->sendable()
            ->get(['id', 'email', 'name']);

        if ($receivers->isEmpty()) {
            $this->commit($chunk, $send, null, [], [], 0, 0);

            return;
        }

        $chunk->forceFill(['attempts' => $chunk->attempts + 1])->save();

        $response = $client->send($this->buildMessage($send, $key, $chunk, $receivers));

        // Retryable and attempts left → let the queue try again.
        if (! $response->carriesRecipientOutcomes() && $response->isRetryable() && $this->attempts() < $this->tries) {
            $this->recordAttempt($chunk, $response);

            throw new \RuntimeException(
                "UniOne chunk {$this->chunkIndex} failed with HTTP {$response->httpStatus} — retrying.",
            );
        }

        /*
         * Both the success path AND the all-failed error (API code 204) carry
         * per-address reasons. Discarding the 204 body would throw away the
         * bounce signal for 500 addresses and retry every one of them next run.
         */
        if ($response->carriesRecipientOutcomes()) {
            $this->commit(
                $chunk,
                $send,
                $response->jobId,
                $receivers->all(),
                $response->failedEmails,
                $response->httpStatus,
                $response->latencyMs,
                $response->apiErrorCode,
            );

            return;
        }

        /*
         * Not retryable, or attempts exhausted.
         *
         * The stored error is the EXPLAINED one, not the raw API string: the log
         * is read when a send failed and the operator needs to know what to
         * change. UniOne's own wording ("the request contains external
         * domain(s)") does not say that the fix is on the account rather than in
         * this admin.
         */
        $this->recordAttempt($chunk, $response);
        $this->failChunk($chunk, $send, \App\Support\UniOne\UniOneErrors::explain(
            $response->apiErrorCode,
            $response->message,
            $response->httpStatus,
        ));
    }

    /**
     * Build the UniOne `message` object.
     *
     * @param  \Illuminate\Support\Collection<int, UniOneReceiver>  $receivers
     * @return array<string, mixed>
     */
    private function buildMessage(UniOneSend $send, UniOneApiKey $key, UniOneSendChunk $chunk, $receivers): array
    {
        return array_filter([
            'recipients' => $receivers->map(function (UniOneReceiver $r) use ($send): array {
                $subs = [];

                if ($r->name !== null && $r->name !== '') {
                    $subs['to_name'] = mb_substr($r->name, 0, 78);
                }

                if ($this->usesTemplate($send)) {
                    // Rendered here, once per address, because the template
                    // personalises the greeting from the receiver's name.
                    $subs['body_html'] = app(\App\Services\UniOne\UniOneTemplateService::class)
                        ->renderFor($r->email, $r->name);
                }

                return array_filter([
                    'email'         => $r->email,
                    'substitutions' => $subs === [] ? null : $subs,
                ]);
            })->values()->all(),

            'subject'    => $send->subject,
            'from_email' => $send->from_email,
            'from_name'  => $send->from_name,
            'reply_to'   => $send->reply_to,

            /*
             * One `substitutions` slot per recipient carries their rendered
             * body, and the shared body references it.
             *
             * This is how a template send stays ONE request for 500 people
             * rather than 500 requests: UniOne substitutes per recipient
             * server-side. A shared literal body could not personalise the
             * greeting; 500 separate calls would blow the rate limit.
             */
            'body' => array_filter([
                'html'      => $this->htmlFor($send),
                'plaintext' => $send->plaintext_body,
            ]),
            'template_engine' => $this->usesTemplate($send) ? 'simple' : 'none',

            'track_links' => $key->track_links ? 1 : 0,
            'track_read'  => $key->track_read ? 1 : 0,

            /*
             * LEFT AT THE DEFAULT 0 — UniOne appends its own unsubscribe footer.
             * Setting 1 needs their support's approval and removing the footer
             * would break compliance, so this is deliberately not configurable.
             */
            'skip_unsubscribe' => 0,

            'idempotence_key' => $chunk->idempotence_key,
        ], static fn ($v): bool => $v !== null && $v !== '' && $v !== []);
    }

    /** Whether this run renders a template per recipient. */
    private function usesTemplate(UniOneSend $send): bool
    {
        return str_starts_with((string) $send->html_body, \App\Services\UniOne\UniOneSendService::TEMPLATE_MARKER);
    }

    /**
     * The shared body.
     *
     * For a template run it is a single substitution placeholder — UniOne
     * expands it per recipient from the `body_html` above. For a raw run it is
     * the literal HTML the operator typed.
     */
    private function htmlFor(UniOneSend $send): string
    {
        return $this->usesTemplate($send) ? '{{body_html}}' : (string) $send->html_body;
    }

    /**
     * Commit the chunk and its recipients atomically.
     *
     * The brief's requirement, and the reason the guard works: `committed_at`,
     * the per-recipient rows and every `last_sent_at` move together or not at
     * all. A crash mid-way leaves the chunk pending and safely retryable.
     *
     * @param  array<int, UniOneReceiver>  $receivers
     * @param  array<string, string>       $failedEmails
     */
    private function commit(
        UniOneSendChunk $chunk,
        UniOneSend $send,
        ?string $jobId,
        array $receivers,
        array $failedEmails,
        int $httpStatus,
        int $latencyMs,
        ?int $apiErrorCode = null,
    ): void {
        $now = now();

        // Address lookup is case-insensitive: UniOne echoes what it was given,
        // but a mismatch in case would silently orphan a failure reason.
        $failedLower = [];
        foreach ($failedEmails as $email => $reason) {
            $failedLower[mb_strtolower(trim((string) $email))] = (string) $reason;
        }

        DB::transaction(function () use ($chunk, $send, $jobId, $receivers, $failedLower, $httpStatus, $latencyMs, $apiErrorCode, $now): void {
            $outcomes = app(UniOneOutcomeService::class);
            $acceptedIds = [];
            $rows = [];
            $failedCount = 0;

            foreach ($receivers as $receiver) {
                $reason = $failedLower[mb_strtolower(trim((string) $receiver->email))] ?? null;

                $rows[] = [
                    'unione_send_id'       => $send->id,
                    'unione_send_chunk_id' => $chunk->id,
                    'unione_receiver_id'   => $receiver->id,
                    'email'                => $receiver->email,
                    'status'               => $reason === null
                        ? UniOneSendRecipient::STATUS_ACCEPTED
                        : UniOneSendRecipient::STATUS_FAILED,
                    'reason'               => $reason,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ];

                if ($reason === null) {
                    $acceptedIds[] = $receiver->id;

                    continue;
                }

                $failedCount++;
                $outcomes->applyFailure($receiver, $reason);
            }

            if ($rows !== []) {
                foreach (array_chunk($rows, 200) as $slice) {
                    UniOneSendRecipient::query()->insert($slice);
                }
            }

            // Only the accepted addresses advance in the rotation. A bounced
            // address keeping its old `last_sent_at` is correct — it was not
            // successfully contacted.
            $outcomes->markSent($acceptedIds, $now);

            $chunk->forceFill([
                'status'         => UniOneSendChunk::STATUS_COMMITTED,
                'job_id'         => $jobId,
                'accepted_count' => count($acceptedIds),
                'failed_count'   => $failedCount,
                'http_status'    => $httpStatus,
                'api_error_code' => $apiErrorCode,
                'latency_ms'     => $latencyMs,
                'committed_at'   => $now,
            ])->save();

            UniOneSend::query()->whereKey($send->id)->update([
                'accepted_count' => DB::raw('accepted_count + ' . count($acceptedIds)),
                'failed_count'   => DB::raw('failed_count + ' . $failedCount),
                'updated_at'     => $now,
            ]);
        });

        $this->finaliseIfDone($send);
    }

    private function recordAttempt(UniOneSendChunk $chunk, UniOneResponse $response): void
    {
        $chunk->forceFill([
            'http_status'    => $response->httpStatus,
            'api_error_code' => $response->apiErrorCode,
            'latency_ms'     => $response->latencyMs,
            // Truncated: an upstream HTML error page must not bloat the row.
            'error'          => mb_substr((string) $response->message, 0, 2000),
        ])->save();
    }

    private function failChunk(UniOneSendChunk $chunk, UniOneSend $send, string $message): void
    {
        $chunk->forceFill([
            'status' => UniOneSendChunk::STATUS_FAILED,
            'error'  => mb_substr($message, 0, 2000),
        ])->save();

        // The run carries the reason too, so the list explains itself without
        // opening the row.
        UniOneSend::query()->whereKey($send->id)->update(['error' => mb_substr($message, 0, 2000)]);

        Log::error('UniOne chunk failed', [
            'send_id' => $send->id,
            'chunk'   => $this->chunkIndex,
            'error'   => $message,
        ]);

        $this->finaliseIfDone($send);
    }

    /** Close the run once no chunk is still pending. */
    private function finaliseIfDone(UniOneSend $send): void
    {
        $pending = UniOneSendChunk::query()
            ->where('unione_send_id', $send->id)
            ->where('status', UniOneSendChunk::STATUS_PENDING)
            ->count();

        if ($pending > 0) {
            return;
        }

        $anyCommitted = UniOneSendChunk::query()
            ->where('unione_send_id', $send->id)
            ->where('status', UniOneSendChunk::STATUS_COMMITTED)
            ->exists();

        UniOneSend::query()->whereKey($send->id)->update([
            'status'       => $anyCommitted ? UniOneSend::STATUS_COMPLETED : UniOneSend::STATUS_FAILED,
            'completed_at' => now(),
        ]);
    }

    /** Queue gave up: the run must not sit at "sending" forever. */
    public function failed(\Throwable $e): void
    {
        $chunk = UniOneSendChunk::query()
            ->where('unione_send_id', $this->sendId)
            ->where('chunk_index', $this->chunkIndex)
            ->first();

        if ($chunk === null || $chunk->isCommitted()) {
            return;
        }

        $send = UniOneSend::query()->find($this->sendId);

        if ($send !== null) {
            $this->failChunk($chunk, $send, $e->getMessage());
        }
    }
}
