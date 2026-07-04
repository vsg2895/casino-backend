<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keeps monthly RANGE partitions of `promotion_email_histories` provisioned
 * ahead of time, so inserts never fall back into the MAXVALUE catch-all (which
 * would defeat partition pruning). Runs monthly via the scheduler; idempotent.
 * No-op on non-MySQL drivers.
 */
class ManagePromotionHistoryPartitions extends Command
{
    protected $signature = 'promotions:manage-history-partitions {--months=6 : Months to keep provisioned ahead}';

    protected $description = 'Provision upcoming monthly partitions for the promotion history table';

    private const string TABLE = 'promotion_email_histories';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->info('Partitioning applies to MySQL only — nothing to do.');

            return self::SUCCESS;
        }

        $months = $this->existingMonths();
        if ($months === []) {
            $this->warn('No monthly partitions found — is ' . self::TABLE . ' partitioned?');

            return self::FAILURE;
        }

        $target = Carbon::now()->addMonths((int) $this->option('months'))->format('Ym');
        $cursor = Carbon::createFromFormat('Ym', max($months))->startOfMonth();
        $added = 0;

        while ($cursor->format('Ym') < $target) {
            $cursor->addMonth();
            $stamp = $cursor->format('Ym');

            if (in_array($stamp, $months, true)) {
                continue;
            }

            // Split a new month partition off the front of p_future.
            DB::statement(sprintf(
                "ALTER TABLE %s REORGANIZE PARTITION p_future INTO ("
                . "PARTITION p%s VALUES LESS THAN (TO_DAYS('%s')), "
                . "PARTITION p_future VALUES LESS THAN (MAXVALUE))",
                self::TABLE,
                $stamp,
                $cursor->copy()->addMonth()->format('Y-m-d'),
            ));

            $months[] = $stamp;
            $added++;
        }

        $this->info("Ensured promotion history partitions through {$target}; added {$added}.");

        return self::SUCCESS;
    }

    /**
     * Existing monthly partition stamps (YYYYMM) for the table.
     *
     * @return list<string>
     */
    private function existingMonths(): array
    {
        $rows = DB::select(
            'SELECT PARTITION_NAME AS name FROM information_schema.PARTITIONS '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL',
            [DB::getDatabaseName(), self::TABLE],
        );

        $months = [];
        foreach ($rows as $row) {
            if (preg_match('/^p(\d{6})$/i', (string) $row->name, $match) === 1) {
                $months[] = $match[1];
            }
        }

        return $months;
    }
}
