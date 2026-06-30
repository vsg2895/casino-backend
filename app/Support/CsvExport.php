<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lightweight streamed CSV exporter.
 *
 * Streams rows straight to the client without buffering the whole dataset in
 * memory, so it scales to large lead/newsletter lists. (The product spec calls
 * for "Export CSV"; phpspreadsheet/maatwebsite-excel cannot run on PHP 8.5, so
 * we stream CSV natively.)
 */
final class CsvExport
{
    /**
     * @param string[]            $headings
     * @param iterable<int, array> $rows
     */
    public static function download(string $filename, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($handle, $headings);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
