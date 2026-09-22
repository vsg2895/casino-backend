<?php

declare(strict_types=1);

namespace App\Services\UniOne;

use App\Models\UniOne\UniOneReceiver;
use App\Support\Spreadsheet\EmailSpreadsheetReader;
use App\Support\Spreadsheet\SpreadsheetScan;
use Illuminate\Support\Carbon;

/**
 * Spreadsheet import for UniOne receivers.
 *
 * Mirrors the Warmup import's shape — upload an .xlsx or .csv, stream it in
 * batches, report rows/imported/duplicates/invalid — as NEW code. Warmup's
 * service is untouched.
 *
 * It reuses {@see EmailSpreadsheetReader}, which is a shared PARSER, not part of
 * the Warmup feature: the newsletter import, the phone import and Warmup all
 * already read through it. Re-implementing xlsx parsing to avoid touching it
 * would be duplication for its own sake, and this class writes only to
 * `unione_receivers`.
 *
 * Takes a file and nothing else, exactly as the Warmup import does. Consent
 * fields are no longer collected here — see UniOneReceiver::scopeSendable for
 * what that means.
 */
class UniOneReceiverImportService
{
    private const BATCH_SIZE = 500;

    public function __construct(private readonly EmailSpreadsheetReader $reader) {}

    /**
     * @return array{rows: int, imported: int, duplicates: int, invalid: int}
     */
    public function import(string $path, string $extension): array
    {
        $scan = new SpreadsheetScan();
        $imported = 0;

        foreach ($this->reader->batches($path, $extension, self::BATCH_SIZE, $scan) as $batch) {
            $imported += $this->writeBatch($batch);
        }

        return [
            'rows'     => $scan->rows,
            'imported' => $imported,
            // Repeats inside the file and addresses already on the list are the
            // same outcome to an operator: nothing was added.
            'duplicates' => ($scan->valid - $imported) + $scan->duplicatesInFile,
            'invalid'    => $scan->invalid,
        ];
    }

    /**
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
                'email'          => $email,
                'status'         => UniOneReceiver::STATUS_ACTIVE,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            $batch,
        );

        /*
         * insertOrIgnore, not insert.
         *
         * The unique index on `email` is what makes a duplicate a no-op rather
         * than an aborted import halfway through a file — and the return value
         * is exactly the "imported" figure, so no follow-up count is needed.
         *
         * An address already on the list keeps its existing status: re-importing
         * a file must never quietly reactivate an address UniOne told us to
         * stop mailing.
         */
        return UniOneReceiver::query()->insertOrIgnore($rows);
    }
}
