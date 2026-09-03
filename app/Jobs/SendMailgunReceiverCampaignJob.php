<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MailgunKey;
use App\Services\MailgunReceiverSelector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fans one credential's run out into per-chunk batch jobs.
 *
 * The outer tier of the same two-job shape {@see SendWarmupCampaignJob} uses.
 * This job selects and dispatches; it never sends, so it finishes in seconds
 * regardless of list size and cannot occupy a worker for the length of a
 * 100k-address run.
 *
 * Memory is bounded by {@see MailgunReceiverSelector::stream()}, which yields
 * keyset-paginated chunks. Only the ID list of each chunk is passed to the batch
 * job — never models — so the queue payload stays small and the worker re-reads
 * fresh state at execution time.
 */
class SendMailgunReceiverCampaignJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'low';

    /** Receivers per dispatched batch job. */
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
        $credential = MailgunKey::find($this->credentialId);

        if ($credential === null) {
            return;
        }

        // Every precondition is re-checked here rather than trusted from the
        // dispatcher: a credential can be disabled, emptied or have its message
        // cleared between the scheduler queuing this and a worker running it.
        $reason = $this->blockedReason($credential);

        if ($reason !== null) {
            Log::info('Mailgun receiver campaign skipped', [
                'mailgun_key_id' => $credential->id,
                'reason'         => $reason,
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

            SendMailgunReceiverBatchJob::dispatch($credential->id, $ids);

            $dispatched++;
            $receivers += count($ids);

            // The chunk's models are of no further use once the IDs are queued;
            // dropping them keeps peak memory at one chunk across the whole run.
            unset($chunk, $ids);
        }

        // Stamped after fan-out, so the dispatcher can tell a credential that has
        // just run from one waiting to. Written with a direct UPDATE to avoid
        // touching `updated_at` semantics other admin screens display.
        DB::table('mailgun_keys')
            ->where('id', $credential->id)
            ->update(['last_run_at' => now()]);

        Log::info('Mailgun receiver campaign dispatched', [
            'mailgun_key_id' => $credential->id,
            'batches'        => $dispatched,
            'receivers'      => $receivers,
        ]);
    }

    /**
     * Why this credential must not run, or null when it may.
     *
     * Kept as one method so the scheduler, the manual "Send now" action and this
     * job all refuse for the same reasons — and so the admin sees the same
     * wording the log records.
     */
    public static function blockedReason(MailgunKey $credential): ?string
    {
        if (! $credential->send_enabled) {
            return 'sending disabled for this credential';
        }

        if (! $credential->isActive()) {
            return 'credential is inactive';
        }

        if (! $credential->canAuthenticate()) {
            return 'credential is missing its domain or API key';
        }

        if ((string) $credential->message_subject === '' || (string) $credential->message_html === '') {
            return 'no message is configured for this credential';
        }

        return null;
    }
}
