<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MailgunReceiver;
use App\Models\MailgunReceiverImport;
use App\Support\Spreadsheet\EmailSpreadsheetReader;
use App\Support\Spreadsheet\SpreadsheetScan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes an uploaded spreadsheet into `mailgun_receivers`.
 *
 * Mirrors {@see NewsletterImportService}: the same streaming reader, the same
 * batched writes, the same "collect failures, never abort the file" contract.
 * It does NOT share that class, because the outcomes differ — this import also
 * reports rows rejected for suppression, which the newsletter import has no
 * concept of.
 *
 * Memory is bounded by {@see EmailSpreadsheetReader::batches()}, a Generator: a
 * 100k-row file is never held in PHP. Each batch is resolved against the
 * database with TWO set queries — existing addresses and suppressed addresses —
 * rather than one query per row, which is what keeps a large import from
 * degenerating into 100k round-trips.
 */
final class MailgunReceiverImportService
{
    /** Rows per read batch. Matches the newsletter importer. */
    private const int BATCH_SIZE = 1000;

    /** Cap on stored rejection detail, so a pathological file cannot write an unbounded blob. */
    private const int MAX_REJECTED_LOGGED = 500;

    public function __construct(private readonly EmailSpreadsheetReader $reader) {}

    /**
     * Import one file, updating the import row as it goes.
     *
     * @return array{imported:int, duplicates:int, suppressed:int, rejected:int}
     */
    public function import(
        MailgunReceiverImport $import,
        string $path,
        string $extension,
    ): array {
        $scan = new SpreadsheetScan();
        $now = Carbon::now();

        $imported = 0;
        $duplicates = 0;
        $suppressed = 0;
        $rejectedRows = [];

        foreach ($this->reader->batches($path, $extension, self::BATCH_SIZE, $scan) as $batch) {
            // The reader already normalised and de-duplicated WITHIN the file.
            // What remains is to resolve the batch against existing state.
            $emails = array_values(array_unique($batch));

            if ($emails === []) {
                continue;
            }

            // One query for addresses we already hold. `withTrashed` matters:
            // a soft-deleted receiver still occupies the unique index, so
            // inserting it again would throw rather than silently duplicate.
            $existing = MailgunReceiver::withTrashed()
                ->whereIn('email', $emails)
                ->pluck('email')
                ->all();

            // One query for suppressed addresses. Re-importing someone who
            // unsubscribed or hard-bounced is the single most damaging thing a
            // bulk import can do, so it is blocked at the door.
            $blocked = DB::table('mailgun_suppressions')
                ->whereIn('email', $emails)
                ->pluck('email')
                ->all();

            $existingSet = array_flip($existing);
            $blockedSet = array_flip($blocked);

            $insert = [];

            foreach ($emails as $email) {
                if (isset($blockedSet[$email])) {
                    $suppressed++;

                    if (count($rejectedRows) < self::MAX_REJECTED_LOGGED) {
                        $rejectedRows[] = $email . ' — suppressed (previously unsubscribed or bounced)';
                    }

                    continue;
                }

                if (isset($existingSet[$email])) {
                    $duplicates++;

                    continue;
                }

                $insert[] = [
                    'email'               => $email,
                    'name'                => null,
                    'source'              => MailgunReceiver::SOURCE_IMPORT,
                    'consent_recorded_at' => $now,
                    'unsubscribe_token'   => MailgunReceiver::newToken(),
                    'is_active'           => true,
                    'sent_count'          => 0,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ];
            }

            if ($insert !== []) {
                // insertOrIgnore, not insert: two concurrent imports of
                // overlapping files would otherwise collide on the unique index
                // and fail the whole batch. Ignored rows are counted as
                // duplicates below, which is what they are.
                $before = count($insert);
                DB::table('mailgun_receivers')->insertOrIgnore($insert);

                $written = MailgunReceiver::whereIn('email', array_column($insert, 'email'))->count();
                $imported += $written;
                $duplicates += max(0, $before - $written);
            }

            // Progress is written per batch so the admin's poll shows movement
            // on a long file rather than jumping from 0 to done.
            $import->forceFill([
                'status'     => MailgunReceiverImport::STATUS_RUNNING,
                'total'      => $scan->rows,
                'imported'   => $imported,
                'duplicates' => $duplicates,
                'suppressed' => $suppressed,
                'rejected'   => $scan->invalid,
            ])->save();

            unset($batch, $emails, $existing, $blocked, $existingSet, $blockedSet, $insert);
        }

        $import->forceFill([
            'status'        => MailgunReceiverImport::STATUS_FINISHED,
            'total'         => $scan->rows,
            'imported'      => $imported,
            'duplicates'    => $duplicates,
            'suppressed'    => $suppressed,
            'rejected'      => $scan->invalid,
            'rejected_rows' => $rejectedRows === [] ? null : Str::limit(implode("\n", $rejectedRows), 60000, ''),
            'finished_at'   => Carbon::now(),
        ])->save();

        return [
            'imported'   => $imported,
            'duplicates' => $duplicates,
            'suppressed' => $suppressed,
            'rejected'   => $scan->invalid,
        ];
    }
}
