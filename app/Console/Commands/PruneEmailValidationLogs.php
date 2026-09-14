<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EmailValidationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deletes validation logs past their retention window.
 *
 * These rows hold visitor email addresses. They exist to answer "why was this
 * address refused" and "where did the month's credits go", and neither question
 * is asked about a year-old attempt — so keeping them indefinitely would be
 * collecting personal data for no purpose.
 *
 * Chunked deletes rather than one statement: this table grows with every
 * subscribe attempt across six sites, and a single unbounded DELETE would hold
 * locks for as long as it takes.
 *
 *   php artisan email-validation:prune
 *   php artisan email-validation:prune --months=6 --dry-run
 */
class PruneEmailValidationLogs extends Command
{
    protected $signature = 'email-validation:prune
                            {--months= : Retention in months (default: config)}
                            {--dry-run : Report what would be deleted, delete nothing}';

    protected $description = 'Delete SendGrid email-validation logs past the retention window';

    public function handle(): int
    {
        $months = (int) ($this->option('months')
            ?? config('services.sendgrid_validation.log_retention_months', 12));

        if ($months < 1) {
            $this->error('Retention must be at least 1 month.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now('UTC')->subMonths($months)->startOfDay();
        $query = EmailValidationLog::query()->where('created_at', '<', $cutoff);
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info("Nothing to prune — no logs older than {$cutoff->toDateString()}.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Would delete {$total} log(s) created before {$cutoff->toDateString()}.");

            return self::SUCCESS;
        }

        $deleted = 0;

        do {
            $batch = (clone $query)->limit(1000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Pruned {$deleted} email-validation log(s) created before {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
