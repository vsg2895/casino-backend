<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EmailSchedule;
use App\Services\ScheduleRecipientService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Fans a scheduled promotion campaign out into recipient BATCHES.
 *
 * The audience (sign-up date window OR newest N, minus promotion opt-outs) is
 * resolved by {@see ScheduleRecipientService} — the single shared definition
 * also used by the admin's recipient preview, count and CSV export, so what an
 * admin previews is precisely who gets mailed.
 *
 * Dispatches ONE {@see SendPromotionBatchJob} per BATCH_SIZE addresses — not
 * one job per email — streaming the audience so the whole list never loads into
 * memory; per-recipient tokens are fetched inside each batch job. Runs on the
 * LOW queue.
 */
class SendScheduledPromotionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'low';

    /** Addresses per batch job. */
    private const int BATCH_SIZE = 100;

    public function __construct(public readonly int $scheduleId)
    {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(ScheduleRecipientService $recipients): void
    {
        $schedule = EmailSchedule::with('site')->find($this->scheduleId);

        if ($schedule === null || ! $schedule->active || $schedule->site === null) {
            return;
        }

        // Respect the per-site promotion toggle up front — nothing to fan out.
        if (! $schedule->site->promotionEmailOrDefault()->active) {
            return;
        }

        $siteId = $schedule->site_id;

        // Delivery transport is per-schedule. Only the provider name + the key ID
        // travel into the batch jobs — never the decrypted key (which is resolved
        // and re-validated inside each batch job at send time).
        $provider = $schedule->provider ?? EmailSchedule::PROVIDER_SMTP;
        $sendgridKeyId = $schedule->sendgrid_key_id;

        // The audience comes from the shared resolver — the SAME query the admin
        // preview, count and CSV export use, so what an admin previews is
        // exactly who gets mailed. Streamed in chunks: the full list is never
        // held in memory, and each chunk becomes one batch job.
        $recipients->eachChunk(
            $schedule,
            self::BATCH_SIZE,
            function (Collection $rows) use ($siteId, $provider, $sendgridKeyId): void {
                SendPromotionBatchJob::dispatch(
                    $siteId,
                    $rows->pluck('email')->all(),
                    $provider,
                    $sendgridKeyId,
                );
            },
        );
    }
}
