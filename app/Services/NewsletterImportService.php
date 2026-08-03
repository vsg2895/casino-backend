<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Newsletter;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Bulk-imports subscriber email addresses from an uploaded spreadsheet
 * (.xlsx / .csv).
 *
 * The file is expected to have an "Email" column; if no such header is present,
 * every cell is scanned so a plain one-column list still imports cleanly.
 * Addresses are normalised (trimmed + lowercased), validated, and de-duplicated
 * across the whole file.
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

        foreach ($this->batches($path, $extension) as $batch) {
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

    /**
     * Stream the file's unique, valid addresses in batches.
     *
     * A generator, so the spreadsheet is never materialised: only the current
     * batch plus the set of addresses already seen is held. That set is what
     * de-duplicates across the WHOLE file (not just within a batch), so the
     * reported total matches what an admin counted in their spreadsheet.
     *
     * @return Generator<int, list<string>>
     */
    private function batches(string $path, string $extension): Generator
    {
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

                    $candidates = $emailColumn !== null
                        ? [$cells[$emailColumn] ?? null]
                        : $cells;

                    foreach ($candidates as $candidate) {
                        $email = $this->normalise($candidate);

                        if ($email === null || isset($seen[$email])) {
                            continue;
                        }

                        $seen[$email] = true;
                        $batch[] = $email;
                    }

                    if (count($batch) >= self::BATCH_SIZE) {
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
}
