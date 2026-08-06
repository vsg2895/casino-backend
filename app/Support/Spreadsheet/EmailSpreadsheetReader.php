<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use Generator;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Streams unique, valid email addresses out of an uploaded .xlsx / .csv.
 *
 * Extracted verbatim from NewsletterImportService so the newsletter import and
 * the warmup import share one parser instead of two drifting copies. The
 * behaviour is unchanged: an "Email" header selects a single column; without one
 * every cell is scanned so a plain one-column list still works; addresses are
 * trimmed, lowercased, validated and de-duplicated across the WHOLE file.
 *
 * A generator, so the spreadsheet is never materialised — only the current batch
 * plus the set of addresses already seen is held.
 */
final class EmailSpreadsheetReader
{
    /**
     * @param  SpreadsheetScan|null  $scan  Optional counters, for callers that
     *                                      need a row-level breakdown (rows,
     *                                      invalid, in-file duplicates).
     * @return Generator<int, list<string>>
     */
    public function batches(
        string $path,
        string $extension,
        int $size,
        ?SpreadsheetScan $scan = null,
    ): Generator {
        $reader = $this->readerFor($extension);
        $reader->open($path);

        try {
            $seen = [];
            $batch = [];
            $emailColumn = null;    // resolved column index once a header is found
            $headerHandled = false;

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();

                    if (! $headerHandled) {
                        $headerHandled = true;
                        $emailColumn = $this->findEmailColumn($cells);

                        // A recognized "Email" header row is metadata — skip it.
                        if ($emailColumn !== null) {
                            continue;
                        }
                        // Otherwise treat the file as header-less and scan every cell
                        // (this row included, in case it already holds data).
                    }

                    $scan?->rows++;

                    $candidates = $emailColumn !== null
                        ? [$cells[$emailColumn] ?? null]
                        : $cells;

                    foreach ($candidates as $candidate) {
                        $email = $this->normalise($candidate);

                        if ($email === null) {
                            // Only count a cell as invalid when it actually held
                            // something — blank cells and padding are not errors.
                            if ($this->isNonEmpty($candidate)) {
                                $scan?->invalid++;
                            }

                            continue;
                        }

                        if (isset($seen[$email])) {
                            $scan?->duplicatesInFile++;

                            continue;
                        }

                        $seen[$email] = true;
                        $scan?->valid++;
                        $batch[] = $email;
                    }

                    if (count($batch) >= $size) {
                        yield $batch;
                        $batch = [];
                    }
                }

                break; // only the first sheet
            }

            if ($batch !== []) {
                yield $batch;
            }
        } finally {
            // Always release the handle — a failed import must not leak it.
            try {
                $reader->close();
            } catch (Throwable) {
                // Closing a reader that never opened is not worth surfacing.
            }
        }
    }

    private function readerFor(string $extension): ReaderInterface
    {
        return strtolower($extension) === 'csv' ? new CsvReader() : new XlsxReader();
    }

    /**
     * Index of the column whose header equals "email" (case-insensitive), or
     * null when there is no such header.
     *
     * @param  array<int, mixed>  $cells
     */
    private function findEmailColumn(array $cells): ?int
    {
        foreach ($cells as $index => $value) {
            if (is_scalar($value) && strtolower(trim((string) $value)) === 'email') {
                return $index;
            }
        }

        return null;
    }

    /** A cell as a normalised address, or null when it is not one. */
    private function normalise(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $email = strtolower(trim((string) $value));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    /** Whether a cell held anything at all (used to tell blank from invalid). */
    private function isNonEmpty(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }
}
