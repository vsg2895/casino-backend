<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ReceiverCampaignCredential;
use App\Mail\MailgunReceiverMessage;
use App\Models\MailgunSuppression;
use App\Support\Mail\PacedDelivery;
use App\Support\Mail\SendingAccount;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mails ONE chunk of receivers through ONE credential, whatever the channel.
 *
 * The per-address worker {@see \App\Jobs\SendMailgunReceiverBatchJob},
 * {@see \App\Jobs\SendSmtpReceiverBatchJob} and
 * {@see \App\Jobs\SendSendgridReceiverBatchJob} delegate to. The list — its
 * receiver table, its daily-claims table and the receiver column of the
 * history — comes from the credential's receiver model, never from this class. Extracted rather than
 * copied: the claim ordering, the counter updates and the hard-bounce rule below
 * are subtle enough that two copies would drift, and a drift here means either
 * duplicate mail or a suppression that only one channel honours.
 *
 * Failure isolation is the point of the inner try/catch: one address the server
 * rejects records a failure and the loop continues. Aborting the chunk would
 * leave the rest unsent and, worse, re-send to everyone already delivered when
 * the job retried.
 */
final class ReceiverCampaignSender
{
    /** Job timeout assumed when the caller does not pass its own. */
    private const int DEFAULT_JOB_TIMEOUT = 240;

    /**
     * Mail one chunk, PACED like a campaign platform ({@see PacedDelivery}):
     * every send takes a slot in the credential's own rolling window
     * (WARMUP_EMAILS_PER_MINUTE a minute, evenly spaced, across every worker),
     * a soft failure is retried once, and when `$jobTimeoutSeconds` is nearly
     * spent the receivers not yet reached come back in `deferred` for the
     * calling job to re-queue. A skipped receiver takes no slot.
     *
     * @param  list<int>  $receiverIds
     * @return array{requested:int,eligible:int,sent:int,failed:int,skipped:int,suppressed:int,deferred:list<int>,duration_ms:int}
     */
    public function sendChunk(
        ReceiverCampaignCredential $credential,
        Mailer $mailer,
        array $receiverIds,
        ?int $jobTimeoutSeconds = null,
    ): array {
        // Re-read inside the job rather than trusting the dispatcher's snapshot:
        // an address may have unsubscribed or been suppressed in the seconds
        // between selection and execution, and mailing it then would be exactly
        // the race the suppression list exists to prevent.
        $model = $credential->campaignReceiverModel();
        $receiverTable = (new $model())->getTable();
        $receiverColumn = $model::receiverForeignKey();
        $claimsTable = $model::dailyClaimsTable();

        $receivers = $model::query()
            ->whereIn('id', $receiverIds)
            ->sendable()
            ->notSuppressed()
            ->get(['id', 'email', 'name', 'unsubscribe_token']);

        $sent = 0;
        $failed = 0;
        // Claimed by an earlier run, a parallel worker, or another channel
        // mailing the same list.
        $skipped = 0;
        $suppressed = 0;
        $startedAt = microtime(true);
        $requested = count($receiverIds);
        $eligible = $receivers->count();

        $historyTable = $credential->campaignHistoryTable();
        $historyColumn = $credential->campaignHistoryColumn();
        $credentialId = (int) $credential->getKey();

        $pacer = PacedDelivery::forAccount(
            SendingAccount::forReceiverCredential($credential),
            $jobTimeoutSeconds ?? self::DEFAULT_JOB_TIMEOUT,
            '[Receivers]',
        );
        /** @var list<int> $deferred */
        $deferred = [];
        $receivers = $receivers->values();

        try {
            foreach ($receivers as $index => $receiver) {
                // Out of time for this invocation: this receiver and the rest go
                // back to the caller BEFORE any claim or slot is taken for them.
                if ($pacer->budgetSpent()) {
                    $deferred = $receivers->slice((int) $index)->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();

                    break;
                }

                $today = Carbon::now()->toDateString();

                // Cross-channel claim FIRST. Winning it is what makes "one email
                // per person per day" true no matter which credentials ran, and
                // it must happen before the per-channel history row so a loser
                // leaves no misleading trace.
                if (! $this->claimDay($claimsTable, $receiverColumn, (int) $receiver->id, $today, $credential, $credentialId)) {
                    $skipped++;

                    continue;
                }

                // The per-channel history row doubles as this channel's own
                // duplicate guard. A conflict here after winning the daily claim
                // is possible only on a retry within the same second, and is
                // treated the same way: skip, never send twice.
                try {
                    $claimId = DB::table($historyTable)->insertGetId([
                        $historyColumn  => $credentialId,
                        $receiverColumn => $receiver->id,
                        'email'         => $receiver->email,
                        'status'        => 'sent',
                        'sent_at'       => now(),
                        'sent_on'       => $today,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                } catch (Throwable) {
                    $skipped++;

                    continue;
                }

                try {
                    $pacer->send($mailer, (string) $receiver->email, new MailgunReceiverMessage($credential, $receiver));

                    // Counters advance per receiver, immediately — not once per
                    // batch. A crash mid-chunk must not leave everyone eligible
                    // again on the next run.
                    DB::table($receiverTable)
                        ->where('id', $receiver->id)
                        ->update([
                            'last_sent_at' => now(),
                            'sent_count'   => DB::raw('sent_count + 1'),
                            'last_error'   => null,
                            'updated_at'   => now(),
                        ]);

                    $sent++;
                } catch (Throwable $e) {
                    $failed++;
                    $message = $e->getMessage();

                    DB::table($historyTable)->where('id', $claimId)->update([
                        'status'     => 'failed',
                        'error'      => mb_strimwidth($message, 0, 1000, '…'),
                        'updated_at' => now(),
                    ]);

                    DB::table($receiverTable)->where('id', $receiver->id)->update([
                        'last_error' => mb_strimwidth($message, 0, 1000, '…'),
                        'updated_at' => now(),
                    ]);

                    // A hard rejection is permanent: keep mailing it and the
                    // sending domain pays for it. Suppression is what stops it —
                    // the shared list is consulted by every selection path.
                    if ($this->isHardFailure($message)) {
                        MailgunSuppression::suppress(
                            $receiver->email,
                            MailgunSuppression::REASON_BOUNCE,
                            'Rejected at send time',
                        );

                        $suppressed++;
                    }

                    // Deliberately no rethrow: one bad address must not abort the
                    // chunk or trigger a retry that re-sends to everyone before it.
                    Log::warning('Receiver campaign send failed', [
                        'channel'       => $credential->campaignChannel(),
                        'credential_id' => $credentialId,
                        'receiver_id'   => $receiver->id,
                        'error'         => $message,
                    ]);
                }
            }
        } finally {
            // Release the chunk before returning so a worker processing many
            // chunks in sequence does not accumulate them.
            unset($receivers);
        }

        return [
            'requested'   => $requested,
            'eligible'    => $eligible,
            'sent'        => $sent,
            'failed'      => $failed,
            'skipped'     => $skipped,
            'suppressed'  => $suppressed,
            'deferred'    => $deferred,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * Take the receiver's slot for today, across every channel of its list.
     *
     * The unique index on (receiver, claim_on) IS the guard — a
     * SELECT-then-INSERT would let two workers both see "free" and both send.
     * A violation means somebody already has today, which is a skip, not an
     * error.
     */
    private function claimDay(
        string $claimsTable,
        string $receiverColumn,
        int $receiverId,
        string $day,
        ReceiverCampaignCredential $credential,
        int $credentialId,
    ): bool {
        try {
            DB::table($claimsTable)->insert([
                $receiverColumn => $receiverId,
                'claim_on'      => $day,
                'channel'       => $credential->campaignChannel(),
                'credential_id' => $credentialId,
                'created_at'    => now(),
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the transport's message indicates a permanently undeliverable
     * address rather than a transient problem.
     *
     * Conservative on purpose: a false positive here suppresses a real person
     * forever, so only unambiguous rejections qualify. Anything else is treated
     * as transient and the address stays in the pool.
     */
    private function isHardFailure(string $message): bool
    {
        $needles = ['invalid mailbox', 'no such user', 'does not exist', 'mailbox unavailable', '550'];
        $haystack = mb_strtolower($message);

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
