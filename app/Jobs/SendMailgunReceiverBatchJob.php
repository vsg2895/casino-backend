<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MailgunKey;
use App\Models\MailgunReceiver;
use App\Models\MailgunReceiverSend;
use App\Mail\MailgunReceiverMessage;
use App\Models\MailgunSuppression;
use App\Services\Mail\PromotionMailerFactory;
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
 * Mails ONE chunk of receivers through one Mailgun credential.
 *
 * The per-recipient worker of the two-tier fan-out, mirroring
 * {@see SendWarmupBatchJob} and {@see SendPromotionBatchJob}. The campaign job
 * streams the batch and dispatches one of these per chunk, so no single job ever
 * holds more than `count($receiverIds)` rows.
 *
 * Only IDs are serialised onto the queue — never models. A payload of hydrated
 * Eloquent models would be both large and stale by the time a worker picked it up.
 *
 * Failure isolation is the point of the inner try/catch: one address that Mailgun
 * rejects records a failure and the loop continues. Aborting the chunk would
 * leave the rest of the list unsent and, worse, re-send to everyone already
 * delivered when the job retried.
 */
class SendMailgunReceiverBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Bulk fan-out belongs on the low queue, behind anything a human awaits. */
    public const string ON_QUEUE = 'low';

    public int $tries = 2;

    /**
     * Must stay BELOW the queue's retry_after, or a slow chunk is handed to a
     * second worker while the first is still sending — the duplicate-send bug
     * the project's job rules call out explicitly.
     */
    public int $timeout = 240;

    /**
     * @param  list<int>  $receiverIds
     */
    public function __construct(
        public readonly int $credentialId,
        public readonly array $receiverIds,
    ) {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(PromotionMailerFactory $mailers): void
    {
        $credential = MailgunKey::find($this->credentialId);

        if ($credential === null || ! $credential->isActive() || ! $credential->canAuthenticate()) {
            Log::warning('Mailgun receiver batch skipped: credential unusable', [
                'mailgun_key_id' => $this->credentialId,
                'batch_size'     => count($this->receiverIds),
            ]);

            return;
        }

        try {
            $mailer = $mailers->mailerForMailgunKey($credential);
        } catch (Throwable $e) {
            // Transport could not be built at all — nothing in this chunk can
            // send, so fail loudly once rather than per address.
            Log::warning('Mailgun receiver batch skipped: transport unavailable', [
                'mailgun_key_id' => $credential->id,
                'error'          => $e->getMessage(),
            ]);

            return;
        }

        // Re-read inside the job rather than trusting the dispatcher's snapshot:
        // an address may have unsubscribed or been suppressed in the seconds
        // between selection and execution, and mailing it then would be exactly
        // the race the suppression list exists to prevent.
        $receivers = MailgunReceiver::query()
            ->whereIn('id', $this->receiverIds)
            ->sendable()
            ->notSuppressed()
            ->get(['id', 'email', 'name', 'unsubscribe_token']);

        $sent = 0;
        $failed = 0;
        // Claimed by an earlier run or a parallel worker — skipped, not failed.
        $skipped = 0;
        $suppressed = 0;
        $startedAt = microtime(true);
        $requested = count($this->receiverIds);
        // Re-checking drops anyone who unsubscribed or was suppressed between
        // selection and now; the gap is worth seeing in the log.
        $eligible = $receivers->count();

        try {
            foreach ($receivers as $receiver) {
                $today = Carbon::now()->toDateString();

                // Claim the send BEFORE attempting it. The unique index
                // (mailgun_key_id, mailgun_receiver_id, sent_on) makes this the
                // concurrency guard: if a retry or a parallel worker already claimed
                // this receiver today, the insert throws and we skip rather than
                // mailing them twice.
                try {
                    $claimId = DB::table('mailgun_receiver_sends')->insertGetId([
                        'mailgun_key_id'      => $credential->id,
                        'mailgun_receiver_id' => $receiver->id,
                        'email'               => $receiver->email,
                        'status'              => MailgunReceiverSend::STATUS_SENT,
                        'sent_at'             => now(),
                        'sent_on'             => $today,
                        'created_at'          => now(),
                        'updated_at'          => now(),
                    ]);
                } catch (Throwable) {
                    // Already claimed today by this credential — not an error.
                    $skipped++;

                    continue;
                }

                try {
                    $mailer->to($receiver->email)->send(
                        new MailgunReceiverMessage($credential, $receiver),
                    );

                    // Counters advance per receiver, immediately — not once per
                    // batch. A crash mid-chunk must not leave everyone eligible
                    // again on the next run.
                    DB::table('mailgun_receivers')
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

                    DB::table('mailgun_receiver_sends')->where('id', $claimId)->update([
                        'status'     => MailgunReceiverSend::STATUS_FAILED,
                        'error'      => mb_strimwidth($message, 0, 1000, '…'),
                        'updated_at' => now(),
                    ]);

                    DB::table('mailgun_receivers')->where('id', $receiver->id)->update([
                        'last_error' => mb_strimwidth($message, 0, 1000, '…'),
                        'updated_at' => now(),
                    ]);

                    // A hard rejection is permanent: keep mailing it and the sending
                    // domain pays for it. Suppression is what stops it — the shared
                    // list is consulted by every selection path, whereas `is_active`
                    // is no longer read by any of them.
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
                    Log::warning('Mailgun receiver send failed', [
                        'mailgun_key_id' => $credential->id,
                        'receiver_id'    => $receiver->id,
                        'error'          => $message,
                    ]);
                }
            }
        } finally {
            // Release the chunk before the job ends so a worker processing many
            // chunks in sequence does not accumulate them.
            unset($receivers);

            // ONE line per batch, always written — in `finally` so a chunk that
            // dies partway through still reports how far it got. Without it, a
            // failed run leaves nothing in the log between "campaign dispatched"
            // and silence, which is the state that makes a 100k send impossible
            // to reason about after the fact.
            Log::info('Mailgun receiver batch finished', [
                'mailgun_key_id' => $credential->id,
                'credential'     => $credential->name,
                'domain'         => $credential->domain,
                // Handed to this job by the campaign fan-out.
                'requested'      => $requested,
                // Survived the re-check for unsubscribe/suppression.
                'eligible'       => $eligible,
                'sent'           => $sent,
                'failed'         => $failed,
                // Already claimed today — a retry or a parallel worker got there first.
                'skipped'        => $skipped,
                // Hard bounces added to the shared suppression list by this batch.
                'suppressed'     => $suppressed,
                'duration_ms'    => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
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
