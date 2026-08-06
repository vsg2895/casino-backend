<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\WarmupEmailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the warmup message to ONE BATCH of addresses over the .env SMTP mailer.
 *
 * The transport is PINNED to config('warmup.mailer') — a literal 'smtp' — not
 * config('mail.admin_mailer') like the admin test buttons. Warmup exists to
 * build the reputation of the mailbox in MAIL_HOST / MAIL_USERNAME /
 * MAIL_PASSWORD; routing it through SendGrid or Mailgun would warm those
 * providers' shared infrastructure instead and silently defeat the feature. So
 * changing MAIL_ADMIN_MAILER must not drag warmup along with it.
 *
 * The From address still comes from config('mail.from.address'), the same rule
 * every admin-originated email uses (see
 * {@see \App\Http\Controllers\Concerns\SendsAdminTestEmail}). No second SMTP
 * implementation exists; warmup only applies that rule in a batch context, which
 * the trait cannot serve because it sends one message and returns an HTTP
 * response.
 *
 * Runs on the LOW queue: warmup is background traffic and must never delay a
 * subscription confirmation or a list import on `high`.
 *
 * A single bad address never stops the batch — it is logged and the loop
 * continues, matching how promotion batches behave.
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

    /** Sending is sequential and network-bound; must stay below `retry_after`. */
    public int $timeout;

    /** @param list<string> $emails */
    public function __construct(
        public readonly array $emails,
        public readonly string $subjectLine,
        public readonly string $bodyText,
    ) {
        $this->onQueue(self::ON_QUEUE);
        $this->timeout = (int) config('warmup.send_timeout', 240);
    }

    public function handle(): void
    {
        if ($this->emails === []) {
            return;
        }

        // Pinned to SMTP by config, never to whatever admin_mailer happens to be.
        $mailer = Mail::mailer(config('warmup.mailer', 'smtp'));
        $fromAddress = config('mail.from.address') ?: null;

        $sent = 0;
        $failed = 0;

        foreach ($this->emails as $email) {
            try {
                $message = (new WarmupEmailMessage($this->subjectLine, $this->bodyText))
                    ->usingFromAddress($fromAddress);

                $mailer->to($email)->send($message);
                $sent++;
            } catch (Throwable $e) {
                // One unroutable address must not abort the rest of the batch.
                $failed++;
                Log::warning('Warmup send failed for a recipient', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // One line per batch, not per recipient.
        Log::info('Warmup batch processed', [
            'sent'   => $sent,
            'failed' => $failed,
            'total'  => count($this->emails),
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Warmup batch job failed', [
            'batch_size' => count($this->emails),
            'error'      => $e->getMessage(),
        ]);
    }
}
