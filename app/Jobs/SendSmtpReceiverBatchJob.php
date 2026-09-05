<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SmtpCredential;
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
 * Mails ONE chunk of receivers through one stored SMTP credential.
 *
 * Twin of {@see SendMailgunReceiverBatchJob}, sharing its send loop through
 * {@see ReceiverCampaignSender}. Only IDs are serialised onto the queue — never
 * models — so the payload stays small and the worker re-reads fresh state at
 * execution time.
 */
class SendSmtpReceiverBatchJob implements ShouldQueue
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
     *
     * The same 240s the Mailgun twin uses, against a chunk of 100 rather than
     * 250, because an SMTP conversation per message is slower than an API call
     * per message.
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
        $credential = SmtpCredential::find($this->credentialId);

        if ($credential === null || ! $credential->isActive() || ! $credential->canAuthenticate()) {
            Log::warning('SMTP receiver batch skipped: credential unusable', [
                'smtp_credential_id' => $this->credentialId,
                'batch_size'         => count($this->receiverIds),
            ]);

            return;
        }

        try {
            $mailer = $mailers->mailerForSmtpCredential($credential);
        } catch (Throwable $e) {
            // Transport could not be built at all — nothing in this chunk can
            // send, so fail loudly once rather than per address.
            Log::warning('SMTP receiver batch skipped: transport unavailable', [
                'smtp_credential_id' => $credential->id,
                'error'              => $e->getMessage(),
            ]);

            return;
        }

        $stats = $sender->sendChunk($credential, $mailer, $this->receiverIds);

        Log::info('SMTP receiver batch finished', [
            'smtp_credential_id' => $credential->id,
            'credential'         => $credential->name,
            'host'               => $credential->host,
            ...$stats,
        ]);
    }
}
