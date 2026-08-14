<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\WarmupEmail;
use App\Services\Mail\WarmupMailResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends ONE BATCH of warmup addresses a rendered site template.
 *
 * TRANSPORT IS UNCHANGED. Still pinned to config('warmup.mailer') — a literal
 * 'smtp' — so warmup keeps going out over the credentials in MAIL_HOST /
 * MAIL_USERNAME / MAIL_PASSWORD. Only the message SOURCE changed: the body used
 * to be free text typed into the form, and is now a real site template rendered
 * by {@see WarmupMailResolver}. Routing warmup through a per-site or per-schedule
 * provider would warm that provider's shared infrastructure instead of this
 * mailbox and silently defeat the feature.
 *
 * The From address still comes from config('mail.from.address'), the same rule
 * every admin-originated email follows.
 *
 * A single bad address never stops the batch — it is logged and the loop
 * continues, matching how promotion batches behave. Addresses that were actually
 * attempted are stamped with `last_sent_at` so the next run rotates past them.
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

    /** Sending is sequential and network-bound; must stay below `retry_after`. */
    public int $timeout;

    /** @param list<string> $emails */
    public function __construct(
        public readonly array $emails,
        public readonly int $siteId,
        public readonly string $template,
    ) {
        $this->onQueue(self::ON_QUEUE);
        $this->timeout = (int) config('warmup.send_timeout', 240);
    }

    public function handle(WarmupMailResolver $resolver): void
    {
        if ($this->emails === []) {
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

        // Pinned to SMTP by config, never to whatever admin_mailer happens to be.
        $mailer = Mail::mailer(config('warmup.mailer', 'smtp'));
        $fromAddress = config('mail.from.address') ?: null;

        $sent = 0;
        $failed = 0;
        $contacted = [];

        foreach ($this->emails as $email) {
            $email = (string) $email;

            try {
                $mailable = $resolver->build($this->template, $site, $email)
                    ->usingFromAddress($fromAddress);

                $mailer->to($email)->send($mailable);
                $sent++;
                $contacted[] = $email;
            } catch (Throwable $e) {
                // One unroutable address must not abort the rest of the batch.
                $failed++;
                Log::warning('Warmup send failed for a recipient', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Only addresses actually reached advance in the rotation, so a failure
        // is retried on the next run rather than silently rotating to the back.
        $this->markContacted($contacted);

        // One line per batch, not per recipient.
        Log::info('Warmup batch processed', [
            'site_id'  => $site->id,
            'template' => $this->template,
            'sent'     => $sent,
            'failed'   => $failed,
            'total'    => count($this->emails),
        ]);
    }

    /** @param list<string> $emails */
    private function markContacted(array $emails): void
    {
        try {
            WarmupEmail::markContacted($emails);
        } catch (Throwable $e) {
            // The mail is already out; losing the rotation stamp only means these
            // addresses may be picked again sooner. Never fail a sent batch here.
            Log::warning('Could not stamp warmup rotation timestamps', [
                'addresses' => count($emails),
                'error'     => $e->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('Warmup batch job failed', [
            'site_id'    => $this->siteId,
            'template'   => $this->template,
            'batch_size' => count($this->emails),
            'error'      => $e->getMessage(),
        ]);
    }
}
