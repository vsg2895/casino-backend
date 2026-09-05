<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SmtpCredential;
use App\Services\MailgunReceiverSelector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fans one SMTP credential's run out into per-chunk batch jobs.
 *
 * Twin of {@see SendMailgunReceiverCampaignJob}, against the same receiver list
 * and through the same {@see MailgunReceiverSelector}. This job selects and
 * dispatches; it never sends, so it finishes in seconds regardless of list size
 * and cannot occupy a worker for the length of a long run.
 *
 * Reached only from the admin's "Run now" button — there is no scheduler entry
 * for this channel, and so no `send_enabled` precondition to check.
 */
class SendSmtpReceiverCampaignJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'low';

    /**
     * Receivers per dispatched batch job.
     *
     * Smaller than the Mailgun fan-out's 250. An own SMTP server sends one
     * message per connection round-trip rather than one API call per message, so
     * a chunk takes appreciably longer; keeping it well inside the batch job's
     * timeout matters more here than the extra dispatches cost.
     */
    private const int CHUNK = 100;

    public int $tries = 1;

    /** Selection only — no sending happens here, so this needs no long window. */
    public int $timeout = 900;

    public function __construct(public readonly int $credentialId)
    {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(MailgunReceiverSelector $selector): void
    {
        $credential = SmtpCredential::find($this->credentialId);

        if ($credential === null) {
            return;
        }

        // Re-checked here rather than trusted from the dispatcher: a credential
        // can be disabled, emptied or have its message cleared between the
        // button press and a worker picking this up.
        $reason = self::blockedReason($credential);

        if ($reason !== null) {
            Log::info('SMTP receiver campaign skipped', [
                'smtp_credential_id' => $credential->id,
                'reason'             => $reason,
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

            SendSmtpReceiverBatchJob::dispatch($credential->id, $ids);

            $dispatched++;
            $receivers += count($ids);

            // The chunk's models are of no further use once the IDs are queued;
            // dropping them keeps peak memory at one chunk across the whole run.
            unset($chunk, $ids);
        }

        // Stamped after fan-out, so the admin can tell a credential that has just
        // run from one waiting to. A direct UPDATE, to avoid touching
        // `updated_at` semantics the credentials table displays.
        DB::table('smtp_credentials')
            ->where('id', $credential->id)
            ->update(['last_run_at' => now()]);

        Log::info('SMTP receiver campaign dispatched', [
            'smtp_credential_id' => $credential->id,
            'batches'            => $dispatched,
            'receivers'          => $receivers,
        ]);
    }

    /**
     * Why this credential must not run, or null when it may.
     *
     * One method so the "Run now" action and this job refuse for the same
     * reasons — and so the admin sees the same wording the log records.
     *
     * No `send_enabled` check, unlike the Mailgun twin: this channel has no
     * scheduler, so a flag governing automatic runs would gate nothing.
     */
    public static function blockedReason(SmtpCredential $credential): ?string
    {
        if (! $credential->isActive()) {
            return 'credential is inactive';
        }

        if (! $credential->canAuthenticate()) {
            return 'credential is missing its host, username, password or from address';
        }

        if ($credential->campaignSubject() === '' || $credential->campaignHtml() === '') {
            return 'no message is configured for this credential';
        }

        return null;
    }
}
