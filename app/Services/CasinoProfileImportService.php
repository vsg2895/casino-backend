<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Casino;
use App\Support\SiteCache;
use App\Support\Spreadsheet\CasinoProfileSheet;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Applies an operator-profile spreadsheet.
 *
 * Synchronous, unlike the newsletter and receiver imports, and deliberately so:
 * those ingest tens of thousands of addresses, while this file has one row per
 * casino — a number in the dozens. Queueing it would buy nothing and cost the
 * editor the immediate per-row feedback that makes a bad column obvious.
 *
 * Rows are keyed on `casino_slug`, which is unique and never changes on this
 * project. Names are exported only as context and are ignored on the way back
 * in, so an editor tidying a name in the sheet cannot silently retarget a row.
 *
 * An unknown slug is REPORTED, never skipped quietly. A typo'd slug that failed
 * silently would look exactly like a successful import that did nothing, which
 * is the worst outcome an import can have.
 */
final class CasinoProfileImportService
{
    /**
     * @return array{updated: int, unchanged: int, errors: list<string>}
     */
    public function import(string $path, string $extension): array
    {
        $reader = $this->readerFor($extension);
        $reader->open($path);

        $updated = 0;
        $unchanged = 0;
        $errors = [];
        /** @var array<int, true> $touchedSites */
        $touchedSites = [];
        /** @var list<string> $touchedSlugs */
        $touchedSlugs = [];

        try {
            $columns = null;   // heading text => field name, resolved from row 1
            $rowNumber = 0;

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber++;
                    $cells = $row->toArray();

                    if ($columns === null) {
                        $columns = $this->mapColumns($cells);

                        if (! isset($columns[CasinoProfileSheet::KEY_COLUMN])) {
                            $errors[] = 'The file has no "Casino slug" column. Export the sheet first and edit that.';

                            return ['updated' => 0, 'unchanged' => 0, 'errors' => $errors];
                        }

                        continue;
                    }

                    $slug = trim((string) ($cells[$columns[CasinoProfileSheet::KEY_COLUMN]] ?? ''));

                    // A blank key is padding at the end of a sheet, not an error.
                    if ($slug === '') {
                        continue;
                    }

                    $casino = Casino::where('slug', $slug)->first();

                    if ($casino === null) {
                        $errors[] = "Row {$rowNumber}: no casino with slug \"{$slug}\".";

                        continue;
                    }

                    $values = [];
                    foreach (CasinoProfileSheet::fields() as $field => $spec) {
                        $index = $columns[$field] ?? null;
                        $values[$field] = $index === null ? '' : (string) ($cells[$index] ?? '');
                    }

                    $attributes = CasinoProfileSheet::attributes($values);

                    // A row the editor never filled in must not CREATE anything.
                    // Without this, importing a freshly exported sheet writes an
                    // all-null profile for every casino and reports each one as
                    // "updated" — junk rows, and a count that says work happened
                    // when none did. An existing profile still gets through, so
                    // blanking a row on purpose remains the way to clear it.
                    if ($casino->detail === null && $this->allEmpty($attributes)) {
                        $unchanged++;

                        continue;
                    }

                    $detail = $casino->detail()->updateOrCreate([], $attributes);

                    // wasChanged() is false when the sheet held the same values
                    // the row already had, which is the normal case for most rows
                    // of a re-imported file. Reporting it separately is what tells
                    // an editor their three edits landed and the other twenty rows
                    // were left alone.
                    if ($detail->wasRecentlyCreated || $detail->wasChanged()) {
                        $updated++;
                        $touchedSlugs[] = $casino->slug;

                        foreach ($casino->sites()->pluck('sites.id') as $siteId) {
                            $touchedSites[(int) $siteId] = true;
                        }
                    } else {
                        $unchanged++;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        $this->refresh(array_keys($touchedSites), $touchedSlugs);

        return ['updated' => $updated, 'unchanged' => $unchanged, 'errors' => $errors];
    }

    /**
     * True when a parsed row carries no value at all.
     *
     * Empty arrays count as empty: a list column the editor left blank comes
     * back as `[]`, not null, and treating that as content would defeat the
     * whole check.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function allEmpty(array $attributes): bool
    {
        foreach ($attributes as $value) {
            if (is_array($value) ? $value !== [] : $value !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Flush and revalidate ONCE for the whole file rather than per row.
     *
     * A twenty-row import would otherwise fire twenty identical revalidation
     * pings at every site, which is both wasteful and a good way to look like an
     * attack to whatever sits in front of the front end.
     *
     * @param  list<int>     $siteIds
     * @param  list<string>  $slugs
     */
    private function refresh(array $siteIds, array $slugs): void
    {
        if ($siteIds === []) {
            return;
        }

        foreach ($siteIds as $siteId) {
            SiteCache::flushSite($siteId);
        }

        $tags = ['casinos', ...array_map(static fn (string $s): string => 'casino:' . $s, array_unique($slugs))];

        RevalidateNextJsSites::dispatch($tags, $siteIds);
    }

    /**
     * Resolve heading text to field names, case- and spacing-insensitive.
     *
     * Matching on the heading rather than on column position means an editor can
     * reorder or delete columns in their spreadsheet and the import still lands
     * the remaining ones correctly.
     *
     * @param  array<int, mixed>  $cells
     * @return array<string, int>
     */
    private function mapColumns(array $cells): array
    {
        $wanted = [];

        foreach (CasinoProfileSheet::identifiers() as $field => $heading) {
            $wanted[$this->key($heading)] = $field;
        }

        foreach (CasinoProfileSheet::fields() as $field => [$heading]) {
            $wanted[$this->key($heading)] = $field;
        }

        $map = [];

        foreach ($cells as $index => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $field = $wanted[$this->key((string) $value)] ?? null;

            if ($field !== null) {
                $map[$field] = $index;
            }
        }

        return $map;
    }

    /** Headings compared without case, spaces or punctuation, so near-misses still match. */
    private function key(string $heading): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($heading));
    }

    private function readerFor(string $extension): ReaderInterface
    {
        return strtolower($extension) === 'csv' ? new CsvReader() : new XlsxReader();
    }
}
