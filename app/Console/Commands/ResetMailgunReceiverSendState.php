<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MailgunReceiverSendStateResetter;
use Illuminate\Console\Command;

/**
 * Clears the send counters on Mailgun receivers — the "Last sent" and "Sent"
 * columns in the admin Receivers table.
 *
 *   php artisan mailgun:reset-receiver-sends                  → every receiver
 *   php artisan mailgun:reset-receiver-sends --clear-errors   → also blank last_error
 *   php artisan mailgun:reset-receiver-sends --force          → no confirmation
 *
 * The reset itself lives in {@see MailgunReceiverSendStateResetter}, shared with
 * the "Reset send counters" button in the admin Mailgun Credentials screen, so
 * the CLI and the panel can never reset different things. Everything here is the
 * console wrapper: confirmation, progress, reporting.
 */
class ResetMailgunReceiverSendState extends Command
{
    protected $signature = 'mailgun:reset-receiver-sends
        {--clear-errors : Also blank last_error, so no stale failure survives the reset}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Reset the last-sent timestamp and sent counter on every Mailgun receiver';

    public function handle(MailgunReceiverSendStateResetter $resetter): int
    {
        $clearErrors = (bool) $this->option('clear-errors');
        $total       = $resetter->pendingCount($clearErrors);

        if ($total === 0) {
            $this->info('No receiver carries send state — nothing to reset.');

            return self::SUCCESS;
        }

        if (! $this->confirmReset($total, $clearErrors)) {
            return self::SUCCESS;
        }

        $progress = $this->output->createProgressBar($total);
        $progress->start();

        $reset = $resetter->reset($clearErrors, static fn (int $done): mixed => $progress->advance($done));

        $progress->finish();

        $this->newLine();
        $this->info(sprintf(
            'Reset last_sent_at and sent_count on %d receiver(s)%s.',
            $reset,
            $clearErrors ? ', and cleared last_error' : '',
        ));
        $this->comment('Every receiver now passes the cooldown filter — the next campaign may mail the whole list.');

        return self::SUCCESS;
    }

    /** Show the blast radius and get a yes, unless --force was passed. */
    private function confirmReset(int $total, bool $clearErrors): bool
    {
        $this->warn(sprintf(
            'About to clear last_sent_at and sent_count%s on %d receiver(s), including soft-deleted rows.',
            $clearErrors ? ' and last_error' : '',
            $total,
        ));
        $this->warn('This removes the cooldown protection: the next campaign can mail every one of them.');

        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Refusing to reset without confirmation. Pass --force when running non-interactively.');

            return false;
        }

        if (! $this->confirm('Proceed?', false)) {
            $this->info('Aborted; nothing was reset.');

            return false;
        }

        return true;
    }
}
