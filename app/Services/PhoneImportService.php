<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NewsletterBasedOnPhone;
use App\Support\Spreadsheet\PhoneSpreadsheetReader;
use App\Support\Spreadsheet\SpreadsheetScan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-imports phone numbers from an uploaded .xlsx / .csv into
 * `newsletters_based_on_phone`.
 *
 * Writes go through insertOrIgnore against the unique `phone` index, so a
 * duplicate costs nothing and needs no existence query — the pattern that made
 * the warmup import fast, and the reason a 50k-row file is ~100 queries rather
 * than 50,000. Numbers are normalised to E.164 by the reader before they get
 * here, so the unique index compares like with like.
 *
 * Streamed throughout: the spreadsheet is never materialised, and a progress
 * callback fires per batch so a queued import can report a live count instead of
 * going silent for minutes.
 */
class PhoneImportService
{
    /** Numbers written per round-trip. */
    private const int BATCH_SIZE = 500;

    public function __construct(private readonly PhoneSpreadsheetReader $reader) {}

    /**
     * @param  callable(array{imported: int, skipped: int, invalid: int, total: int}): void|null  $onProgress
     * @return array{imported: int, skipped: int, invalid: int, total: int}
     *         `skipped` covers both repeats inside the file and numbers already
     *         on the list — from the admin's point of view the same outcome:
     *         nothing was added. `invalid` counts non-empty cells that were not
     *         usable numbers, which for phone data is the figure that actually
     *         tells an admin their file was wrong.
     */
    public function import(string $path, string $extension, ?callable $onProgress = null): array
    {
        $scan = new SpreadsheetScan();
        $imported = 0;

        foreach ($this->reader->batches($path, $extension, self::BATCH_SIZE, $scan) as $batch) {
            $imported += $this->writeBatch($batch);

            // `$onProgress?->(...)` is not valid syntax — nullsafe applies to member
            // access, not to invoking a callable.
            if ($onProgress !== null) {
                $onProgress($this->tally($scan, $imported));
            }
        }

        return $this->tally($scan, $imported);
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
            static fn (string $phone): array => [
                'phone'      => $phone,
                'opted_out'  => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $batch,
        );

        return DB::table((new NewsletterBasedOnPhone())->getTable())->insertOrIgnore($rows);
    }

    /**
     * The running totals, in the shape both the progress callback and the final
     * result use — one definition, so a polled figure and the finished figure can
     * never be computed differently.
     *
     * @return array{imported: int, skipped: int, invalid: int, total: int}
     */
    private function tally(SpreadsheetScan $scan, int $imported): array
    {
        return [
            'imported' => $imported,
            'skipped'  => ($scan->valid - $imported) + $scan->duplicatesInFile,
            'invalid'  => $scan->invalid,
            'total'    => $scan->rows,
        ];
    }
}
