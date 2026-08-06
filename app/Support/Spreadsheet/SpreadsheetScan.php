<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

/**
 * Counters accumulated while a spreadsheet is read.
 *
 * Passed in by the caller and mutated by {@see EmailSpreadsheetReader}, rather
 * than kept on the reader itself: the reader stays stateless and safe to share,
 * and a caller that does not care about the breakdown (the newsletter import)
 * simply passes nothing.
 */
final class SpreadsheetScan
{
    /** Data rows seen, excluding a recognised header row. */
    public int $rows = 0;

    /** Cells that held a usable, correctly formatted address. */
    public int $valid = 0;

    /** Non-empty cells that were not valid addresses. */
    public int $invalid = 0;

    /** Valid addresses that had already appeared earlier in the same file. */
    public int $duplicatesInFile = 0;
}
