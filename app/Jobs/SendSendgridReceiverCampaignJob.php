<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SendgridKey;
use App\Services\MailgunReceiverSelector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fans one SendGrid key's run out into per-chunk batch jobs, against the
 * SendGrid receiver list.
 *
 * Twin of {@see SendMailgunReceiverCampaignJob} and
 * {@see SendSmtpReceiverCampaignJob}, through the same
 * {@see MailgunReceiverSelector} — the key names its list, the selector does
 * the rest. This job selects and dispatches; it never sends, so it finishes in
 * seconds regardless of list size.
 *
 * Reached only from the admin's "Run now" button, like the SMTP twin — there is
 * no scheduler entry for this channel, and so no `send_enabled` flag.
 */
class SendSendgridReceiverCampaignJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'low';

    /** Receivers per dispatched batch job. One API call per message, like Mailgun. */
    private const int CHUNK = 250;

    public int $tries = 1;

    /** Selection only — no sending happens here, so this needs no long window. */
    public int $timeout = 900;

    public function __construct(public readonly int $credentialId)
    {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(MailgunReceiverSelector $selector): void
    {
        $credential = SendgridKey::find($this->credentialId);

        if ($credential === null) {
            return;
        }

        // Re-checked here rather than trusted from the dispatcher: a key can be
        // disabled, emptied or have its message cleared between the button
        // press and a worker picking this up.
        $reason = self::blockedReason($credential);

        if ($reason !== null) {
            Log::info('SendGrid receiver campaign skipped', [
                'sendgrid_key_id' => $credential->id,
                'reason'          => $reason,
            ]);

            return;
        }

        $dispatched = 0;
        $receivers = 0;

        foreach ($selector->stream($credential, self::CHUNK) as $chunk) {
            $ids = $chunk->pluck('id')->all();

            if ($ids === []) {
                continue;
            }

            SendSendgridReceiverBatchJob::dispatch($credential->id, $ids);

            $dispatched++;
            $receivers += count($ids);

            unset($chunk, $ids);
        }

        // A direct UPDATE, so `updated_at` keeps meaning "the key was edited".
        DB::table('sendgrid_keys')
            ->where('id', $credential->id)
            ->update(['last_run_at' => now()]);

        Log::info('SendGrid receiver campaign dispatched', [
            'sendgrid_key_id' => $credential->id,
            'batches'         => $dispatched,
            'receivers'       => $receivers,
        ]);
    }

    /**
     * Why this key must not run, or null when it may.
     *
     * One method so "Run now" and this job refuse for the same reasons, in the
     * same words the log records.
     *
     * The From address is required here even though {@see SendgridKey::canAuthenticate()}
     * does not ask for it: schedules take their sender from a site template, a
     * receiver message has none, and SendGrid refuses a sender it has not
     * verified rather than falling back to anything.
     */
    public static function blockedReason(SendgridKey $credential): ?string
    {
        if (! $credential->isActive()) {
            return 'credential is inactive';
        }

        if (! $credential->canAuthenticate()) {
            return 'credential is missing its API key';
        }

        if (trim((string) $credential->from_address) === '') {
            return 'credential has no From address — set a verified SendGrid sender on the key';
        }

        if ($credential->campaignSubject() === '' || $credential->campaignHtml() === '') {
            return 'no message is configured for this credential';
        }

        return null;
    }
}
