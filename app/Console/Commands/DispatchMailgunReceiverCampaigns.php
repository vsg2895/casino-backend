<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendMailgunReceiverCampaignJob;
use App\Models\MailgunKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Queues a run for every credential whose receiver sending is switched on.
 *
 * Dispatch only — it queues campaign jobs and returns, so the scheduler tick is
 * never held open by a send. All the real work happens on the `low` queue.
 *
 * Safe to run repeatedly, which matters because the scheduler will: the cooldown
 * filter retires anyone recently contacted, and the unique index on
 * (mailgun_key_id, mailgun_receiver_id, sent_on) refuses a second claim for the
 * same person on the same day. Two overlapping ticks therefore cannot double-send.
 */
class DispatchMailgunReceiverCampaigns extends Command
{
    protected $signature = 'mailgun:dispatch-receivers {--credential= : Run one credential by id}';

    protected $description = 'Queue receiver sending runs for enabled Mailgun credentials';

    public function handle(): int
    {
        $query = MailgunKey::query()->where('send_enabled', true);

        if ($this->option('credential') !== null) {
            $query->where('id', (int) $this->option('credential'));
        }

        $queued = 0;
        $skipped = [];

        // Cursor, not get(): the credential list is small today but this keeps
        // the command's memory independent of it either way.
        foreach ($query->cursor() as $credential) {
            $reason = SendMailgunReceiverCampaignJob::blockedReason($credential);

            if ($reason !== null) {
                $skipped[] = "#{$credential->id} ({$reason})";

                continue;
            }

            SendMailgunReceiverCampaignJob::dispatch($credential->id);
            $queued++;
        }

        // Logged rather than only echoed: this runs unattended from cron, where
        // console output goes nowhere.
        Log::info('Mailgun receiver dispatch tick', [
            'queued'  => $queued,
            'skipped' => $skipped,
        ]);

        $this->info("Queued {$queued} campaign(s).");

        foreach ($skipped as $line) {
            $this->line("  skipped {$line}");
        }

        return self::SUCCESS;
    }
}
