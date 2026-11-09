<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SendgridKey;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\ReceiverCampaignSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mails ONE chunk of SendGrid receivers through one SendGrid key.
 *
 * Twin of {@see SendMailgunReceiverBatchJob}, sharing its send loop through
 * {@see ReceiverCampaignSender}. Only IDs are serialised onto the queue — never
 * models — so the payload stays small and the worker re-reads fresh state.
 */
class SendSendgridReceiverBatchJob implements ShouldQueue
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
     * second worker while the first is still sending. Same 240s as the Mailgun
     * twin, for the same chunk size and the same one-API-call-per-message cost.
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

    public function handle(PromotionMailerFactory $mailers, ReceiverCampaignSender $sender): void
    {
        $credential = SendgridKey::find($this->credentialId);

        if ($credential === null || ! $credential->isActive() || ! $credential->canAuthenticate()) {
            Log::warning('SendGrid receiver batch skipped: credential unusable', [
                'sendgrid_key_id' => $this->credentialId,
                'batch_size'      => count($this->receiverIds),
            ]);

            return;
        }

        try {
            $mailer = $mailers->mailerForKey($credential);
        } catch (Throwable $e) {
            // Transport could not be built at all — nothing in this chunk can
            // send, so fail loudly once rather than per address.
            Log::warning('SendGrid receiver batch skipped: transport unavailable', [
                'sendgrid_key_id' => $credential->id,
                'error'           => $e->getMessage(),
            ]);

            return;
        }

        $stats = $sender->sendChunk($credential, $mailer, $this->receiverIds, $this->timeout);
        $deferred = $stats['deferred'];
        $stats['deferred'] = count($deferred);

        Log::info('SendGrid receiver batch finished', [
            'sendgrid_key_id' => $credential->id,
            'credential'      => $credential->name,
            ...$stats,
        ]);

        // The paced send ran out of time for this invocation: the receivers it
        // did not reach go to a new batch of the same credential. Each kept its
        // place — no claim and no slot was taken for them.
        if ($deferred !== []) {
            self::dispatch($credential->id, $deferred);

            Log::info('SendGrid receiver batch: time budget reached; remaining receivers re-queued', [
                'credential_id' => $credential->id,
                'requeued'      => count($deferred),
            ]);
        }
    }
}
