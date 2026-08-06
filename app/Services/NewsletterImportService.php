<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Newsletter;
use App\Support\Spreadsheet\EmailSpreadsheetReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-imports subscriber email addresses from an uploaded spreadsheet
 * (.xlsx / .csv).
 *
 * Parsing is delegated to {@see EmailSpreadsheetReader}, shared with the warmup
 * import: an "Email" header selects a column, otherwise every cell is scanned,
 * and addresses are normalised, validated and de-duplicated across the file.
 *
 * Everything is done in BATCHES, which is what makes a 50k-row file finish in
 * seconds. Per batch:
 *   1. ONE select resolves which addresses already exist (including soft-deleted
 *      ones, which the (site_id, email) unique index still covers);
 *   2. ONE multi-row insert adds the new addresses;
 *   3. ONE update restores the previously-removed ones, plus at most one more to
 *      promote them to verified.
 *
 * That is ~4 queries per {@see BATCH_SIZE} addresses instead of the 2 queries
 * PER ADDRESS a firstOrCreate loop costs. The reader is streamed and only one
 * batch is ever held in memory, so file size drives runtime linearly and memory
 * barely moves.
 *
 * Because the inserts bypass Eloquent, the per-stream unsubscribe tokens that
 * {@see Newsletter::booted()} would normally generate are built here instead —
 * every imported row still gets both, or the unsubscribe links would break.
 */
class NewsletterImportService
{
    /** Addresses resolved and written per round-trip. */
    private const int BATCH_SIZE = 500;

    public function __construct(private readonly EmailSpreadsheetReader $reader) {}

    /**
     * Import every address in the file for one site.
     *
     * @param  null|callable(array{imported: int, skipped: int, total: int}): void  $onProgress
     *         Called after each committed batch with the running totals, so a
     *         queued import can publish live progress. Never called with
     *         uncommitted numbers.
     * @return array{imported: int, skipped: int, total: int}
     */
    public function import(
        int $siteId,
        string $path,
        string $extension,
        bool $verified = false,
        ?callable $onProgress = null,
    ): array {
        $imported = 0;
        $skipped = 0;
        $total = 0;

        foreach ($this->reader->batches($path, $extension, self::BATCH_SIZE) as $batch) {
            $total += count($batch);

            $outcome = DB::transaction(
                fn (): array => $this->writeBatch($siteId, $batch, $verified),
            );

            $imported += $outcome['imported'];
            $skipped += $outcome['skipped'];

            if ($onProgress !== null) {
                $onProgress(['imported' => $imported, 'skipped' => $skipped, 'total' => $total]);
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'total' => $total];
    }

    /**
     * Resolve one batch against the table and write it.
     *
     * @param  list<string>  $batch
     * @return array{imported: int, skipped: int}
     */
    private function writeBatch(int $siteId, array $batch, bool $verified): array
    {
        $table = (new Newsletter())->getTable();
        $now = Carbon::now();

        // One lookup for the whole batch. The query builder is used directly so
        // the soft-delete scope does not hide removed rows — they must be found
        // and restored, not inserted over (the unique key still covers them).
        $existing = DB::table($table)
            ->where('site_id', $siteId)
            ->whereIn('email', $batch)
            ->get(['id', 'email', 'verified', 'deleted_at'])
            ->keyBy('email');

        $insert = [];
        $restore = [];
        $promote = [];
        $skipped = 0;

        foreach ($batch as $email) {
            $row = $existing->get($email);

            if ($row === null) {
                $insert[] = [
                    'site_id'                     => $siteId,
                    'email'                       => $email,
                    'verified'                    => $verified,
                    // Eloquent's creating hook is bypassed by a bulk insert, so
                    // both stream tokens are generated explicitly here.
                    'unsubscribe_token'           => Newsletter::generateUnsubscribeToken(),
                    'promotion_unsubscribe_token' => Newsletter::generateUnsubscribeToken(),
                    'created_at'                  => $now,
                    'updated_at'                  => $now,
                ];

                continue;
            }

            if ($row->deleted_at !== null) {
                // Re-importing a removed contact restores it. Verification is
                // upgrade-only: importing as verified promotes an unverified row,
                // but importing as unverified never downgrades someone who was
                // already verified before removal.
                $restore[] = $row->id;

                if ($verified && ! $row->verified) {
                    $promote[] = $row->id;
                }

                continue;
            }

            $skipped++; // already an active subscriber
        }

        if ($insert !== []) {
            DB::table($table)->insert($insert);
        }

        if ($restore !== []) {
            DB::table($table)->whereIn('id', $restore)->update(['deleted_at' => null, 'updated_at' => $now]);
        }

        if ($promote !== []) {
            DB::table($table)->whereIn('id', $promote)->update(['verified' => true, 'updated_at' => $now]);
        }

        return ['imported' => count($insert) + count($restore), 'skipped' => $skipped];
    }

}
