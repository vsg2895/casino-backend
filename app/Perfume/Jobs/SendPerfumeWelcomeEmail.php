<?php

declare(strict_types=1);

namespace App\Perfume\Jobs;

use App\Perfume\Mail\PerfumeWelcomeEmail;
use App\Perfume\Models\Subscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The confirmation a visitor gets after subscribing on the storefront — the
 * only email this shop sends.
 *
 * It goes through the `sendgrid` mailer, which is SendGrid's Web API using
 * SENDGRID_API_KEY from .env. That is the same transport the casino sites'
 * public subscription mail uses, and it is deliberately NOT the default
 * mailer: admin test mail over SMTP stays unaffected either way.
 *
 * `high` queue: a person is waiting for it.
 */
class SendPerfumeWelcomeEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'high';

    /** SendGrid's Web API transport, keyed from .env. See config/mail.php. */
    public const string MAILER = 'sendgrid';

    public int $timeout = 60;

    public int $tries = 2;

    public function __construct(public readonly int $subscriberId)
    {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(): void
    {
        $subscriber = Subscriber::query()->find($this->subscriberId);

        if ($subscriber === null || ! $subscriber->active) {
            return;
        }

        // Say so once, clearly, rather than letting the transport fail per
        // message with the real cause (an unconfigured .env) buried inside a
        // per-recipient error.
        if (trim((string) config('mail.mailers.sendgrid.key', '')) === '') {
            Log::warning('Perfume welcome email skipped: SENDGRID_API_KEY is not set', [
                'subscriber' => $subscriber->id,
            ]);

            return;
        }

        try {
            Mail::mailer(self::MAILER)->to($subscriber->email)->send(new PerfumeWelcomeEmail($subscriber));
            $subscriber->forceFill(['last_sent_at' => now()])->save();
        } catch (\Throwable $e) {
            // A broken mailbox must not retry forever behind a visitor's click.
            Log::warning('Perfume welcome email failed', [
                'subscriber' => $subscriber->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
