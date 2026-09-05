<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MailgunKey;
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
 * The send loop itself lives in {@see ReceiverCampaignSender}, shared with
 * {@see SendSmtpReceiverBatchJob}. This job is the Mailgun-specific wrapper:
 * resolve the credential, build the transport, log the result.
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

    public function handle(PromotionMailerFactory $mailers, ReceiverCampaignSender $sender): void
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

        $stats = $sender->sendChunk($credential, $mailer, $this->receiverIds);

        // ONE line per batch. Without it, a failed run leaves nothing in the log
        // between "campaign dispatched" and silence, which is the state that
        // makes a 100k send impossible to reason about after the fact.
        Log::info('Mailgun receiver batch finished', [
            'mailgun_key_id' => $credential->id,
            'credential'     => $credential->name,
            'domain'         => $credential->domain,
            ...$stats,
        ]);
    }
}
