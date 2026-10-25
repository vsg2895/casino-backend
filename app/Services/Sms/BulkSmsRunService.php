<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Jobs\SendBulkSmsJob;
use App\Models\TwilioConfig;
use App\Services\PhoneRecipientService;
use App\Services\QueuedRunOutcome;
use App\Services\QueuedRunRefusal;
use App\Support\Phone\PhoneAudienceFilter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Starting a bulk SMS run over the standalone phone list.
 *
 * Only queues: the actual sending happens in {@see SendBulkSmsJob} and its
 * batches, on the low-priority queue. The audience is {@see PhoneRecipientService},
 * the same code the admin preview uses, so the count quoted is the count sent.
 */
final class BulkSmsRunService
{
    /** How long the "a bulk run is in flight" lock is held for. */
    public const int RUN_LOCK_SECONDS = 900;

    public function __construct(private readonly PhoneRecipientService $recipients) {}

    /**
     * Guarded by a cross-process lock rather than a UI-only disabled button. Two
     * concurrent runs would message the same numbers twice, and a duplicate SMS is
     * billed and cannot be recalled, so the guard has to hold across tabs,
     * browsers and app servers. The lock is released by the fan-out job when it
     * finishes, and expires on its own if the worker dies.
     */
    public function start(TwilioConfig $config, string $body, PhoneAudienceFilter $filter, ?int $userId): QueuedRunOutcome
    {
        // Checked before anything is queued, so a misconfigured credential is one
        // clear error rather than a queued run that fails per recipient.
        if (! $config->hasSender()) {
            return QueuedRunOutcome::refused(
                QueuedRunRefusal::Unsendable,
                "\"{$config->name}\" has no sender configured. Add a Twilio phone number or a Messaging Service SID first.",
            );
        }

        // Resolved before the lock is taken: telling the admin "nobody matches"
        // costs one COUNT and avoids holding a 15-minute lock for a run that has
        // nothing to do.
        $total = $this->recipients->count($filter);

        if ($total === 0) {
            return QueuedRunOutcome::refused(
                QueuedRunRefusal::Unsendable,
                'No numbers match the selected filters, so nothing was queued.',
            );
        }

        $lock = Cache::lock(SendBulkSmsJob::runLockKey(), self::RUN_LOCK_SECONDS);

        if (! $lock->get()) {
            return QueuedRunOutcome::refused(
                QueuedRunRefusal::AlreadyRunning,
                'A bulk SMS run is already in progress. Wait for it to finish before starting another.',
            );
        }

        // The owner token travels with the job so the job — and only the job — can
        // release this exact lock; a later run's lock can never be released by an
        // earlier, slower one.
        SendBulkSmsJob::dispatch(
            $config->id,
            $body,
            $filter->toArray(),
            $lock->owner(),
        );

        Log::info('Bulk SMS run queued', [
            'twilio_config_id' => $config->id,
            'recipients' => $total,
            'filters' => $filter->describe(),
            'admin_id' => $userId,
        ]);

        return QueuedRunOutcome::queued($total, "Queued an SMS to {$total} number(s) ({$filter->describe()}).");
    }
}
