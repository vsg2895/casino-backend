<?php

declare(strict_types=1);

namespace App\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamed Excel (.xlsx) exporter — the .xlsx sibling of {@see CsvExport}.
 *
 * Written with OpenSpout, the library the spreadsheet IMPORTS already use
 * (phpspreadsheet cannot run on this PHP version). Rows are written one at a
 * time from the iterable, so a large list never sits in memory as a whole;
 * OpenSpout assembles the workbook in its temp folder and copies the finished
 * zip to the response on close.
 *
 * The heading row is bold, and every column gets the same readable width so an
 * address is not cut off when the file is opened.
 */
final class XlsxExport
{
    /** Column width in Excel's character units — wide enough for a long address. */
    private const float COLUMN_WIDTH = 36;

    /**
     * @param string[]                         $headings
     * @param iterable<int, array<int, mixed>> $rows
     */
    public static function download(string $filename, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows): void {
            $options = new Options();
            $options->setColumnWidthForRange(self::COLUMN_WIDTH, 1, max(1, count($headings)));

            $writer = new Writer($options);
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValuesWithStyle($headings, new Style(fontBold: true)));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }

            $writer->close();
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
