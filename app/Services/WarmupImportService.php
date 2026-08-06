<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WarmupEmail;
use App\Support\Spreadsheet\EmailSpreadsheetReader;
use App\Support\Spreadsheet\SpreadsheetScan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-imports warmup addresses from an uploaded .xlsx / .csv.
 *
 * Parsing is the SAME {@see EmailSpreadsheetReader} the newsletter import uses —
 * header detection, normalisation, validation and in-file de-duplication are not
 * reimplemented here. What differs is the write and the reporting:
 *
 *  - writes go through insertOrIgnore against the unique `email` index, so a
 *    duplicate costs nothing and needs no existence query;
 *  - a {@see SpreadsheetScan} is threaded through the reader so the admin gets a
 *    real breakdown (rows read, imported, duplicates, invalid) rather than just
 *    a count of what landed.
 *
 * Batched and streamed: a large file neither loads into memory nor issues one
 * query per row.
 */
class WarmupImportService
{
    /** Addresses written per round-trip. */
    private const int BATCH_SIZE = 500;

    public function __construct(private readonly EmailSpreadsheetReader $reader) {}

    /**
     * @return array{rows: int, imported: int, duplicates: int, invalid: int}
     *         `duplicates` covers both repeats inside the file and addresses
     *         already on the list — from the admin's point of view they are the
     *         same outcome: nothing was added.
     */
    public function import(string $path, string $extension): array
    {
        $scan = new SpreadsheetScan();
        $imported = 0;

        foreach ($this->reader->batches($path, $extension, self::BATCH_SIZE, $scan) as $batch) {
            $imported += $this->writeBatch($batch);
        }

        return [
            'rows'       => $scan->rows,
            'imported'   => $imported,
            'duplicates' => ($scan->valid - $imported) + $scan->duplicatesInFile,
            'invalid'    => $scan->invalid,
        ];
    }

    /**
     * Insert a batch, ignoring rows that collide with the unique index.
     *
     * insertOrIgnore returns the number of rows actually written, which is
     * exactly the "imported" figure — no follow-up count needed.
     *
     * @param  list<string>  $batch
     */
    private function writeBatch(array $batch): int
    {
        if ($batch === []) {
            return 0;
        }

        $now = Carbon::now();
        $rows = array_map(
            static fn (string $email): array => [
                'email'      => $email,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $batch,
        );

        return DB::table((new WarmupEmail())->getTable())->insertOrIgnore($rows);
    }
}
