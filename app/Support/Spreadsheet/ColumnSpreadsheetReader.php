<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use Generator;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Streams unique, valid values out of one column of an uploaded .xlsx / .csv.
 *
 * The traversal, header detection, de-duplication and counting are all here; a
 * subclass supplies only the two things that actually differ per data type —
 * which header names identify the column ({@see headerNames()}) and how a cell
 * becomes a canonical value ({@see normalise()}).
 *
 * A generator, so the spreadsheet is never materialised: only the current batch
 * plus the set of values already seen is held. That is what lets a 50k-row file
 * import in flat memory.
 *
 * {@see EmailSpreadsheetReader} predates this class and still carries its own
 * copy of the same traversal, deliberately: it is on the live subscription and
 * warmup import paths, so it is not being restructured here. Folding it onto this
 * base is a safe, behaviour-preserving follow-up.
 */
abstract class ColumnSpreadsheetReader
{
    /**
     * Header labels (lowercase) that identify the column to read.
     *
     * @return list<string>
     */
    abstract protected function headerNames(): array;

    /** A cell as a canonical value, or null when it is not one. */
    abstract protected function normalise(mixed $value): ?string;

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
        // Counters are always accumulated, into a throwaway instance when the
        // caller did not supply one.
        //
        // Not a style choice. Incrementing through the nullsafe operator —
        // `$scan` + `?->` + `rows++` — is a write context, which PHP rejects with
        // a FATAL parse error, so the null case has to be removed rather than
        // guarded at each use. Defaulting here keeps the semantics a caller that
        // passes null already expected: the counters simply go nowhere.
        $scan ??= new SpreadsheetScan();

        $reader = $this->readerFor($extension);
        $reader->open($path);

        try {
            $seen = [];
            $batch = [];
            $column = null;         // resolved column index once a header is found
            $headerHandled = false;

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();

                    if (! $headerHandled) {
                        $headerHandled = true;
                        $column = $this->findColumn($cells);

                        // A recognised header row is metadata — skip it.
                        if ($column !== null) {
                            continue;
                        }
                        // Otherwise treat the file as header-less and scan every
                        // cell (this row included, in case it already holds data),
                        // so a plain one-column list still works.
                    }

                    $scan->rows++;

                    $candidates = $column !== null
                        ? [$cells[$column] ?? null]
                        : $cells;

                    foreach ($candidates as $candidate) {
                        $value = $this->normalise($candidate);

                        if ($value === null) {
                            // Only count a cell as invalid when it actually held
                            // something — blank cells and padding are not errors.
                            if ($this->isNonEmpty($candidate)) {
                                $scan->invalid++;
                            }

                            continue;
                        }

                        if (isset($seen[$value])) {
                            $scan->duplicatesInFile++;

                            continue;
                        }

                        $seen[$value] = true;
                        $scan->valid++;
                        $batch[] = $value;
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
     * Index of the first column whose header matches {@see headerNames()}
     * (case-insensitive), or null when there is no such header.
     *
     * @param  array<int, mixed>  $cells
     */
    private function findColumn(array $cells): ?int
    {
        $wanted = $this->headerNames();

        foreach ($cells as $index => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            if (in_array(strtolower(trim((string) $value)), $wanted, true)) {
                return $index;
            }
        }

        return null;
    }

    /** Whether a cell held anything at all (used to tell blank from invalid). */
    private function isNonEmpty(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }
}
