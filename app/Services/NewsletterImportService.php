<?php

declare(strict_types=1);

namespace App\Services;

use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Extracts subscriber email addresses from an uploaded spreadsheet
 * (.xlsx / .csv). The file is expected to have an "Email" column; if no such
 * header is present, every cell is scanned so a plain one-column list still
 * imports cleanly. Returns unique, lowercased, RFC-valid addresses.
 */
class NewsletterImportService
{
    /** @return list<string> */
    public function emails(string $path, string $extension): array
    {
        $reader = $this->readerFor($extension);
        $reader->open($path);

        $emails = [];
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

                if ($emailColumn !== null) {
                    $this->collect($emails, $cells[$emailColumn] ?? null);
                } else {
                    foreach ($cells as $cell) {
                        $this->collect($emails, $cell);
                    }
                }
            }

            break; // only the first sheet
        }

        $reader->close();

        return array_values(array_unique($emails));
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

    /** @param  list<string>  $emails */
    private function collect(array &$emails, mixed $value): void
    {
        if (! is_scalar($value)) {
            return;
        }

        $email = strtolower(trim((string) $value));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $emails[] = $email;
        }
    }
}
