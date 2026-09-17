<?php

declare(strict_types=1);

namespace App\Services\UniOne;

use App\Models\UniOne\UniOneReceiver;
use Illuminate\Support\Carbon;

/**
 * CSV import with column mapping, dry-run preview and a per-row verdict.
 *
 * ── Consent is enforced HERE as well as in the scope ────────────────────────
 *
 * The brief: "consent_source and consent_at are mandatory on every receiver,
 * INCLUDING imports". An import is exactly where that rule gets bypassed in
 * practice — a spreadsheet arrives, nobody remembers where it came from, and it
 * goes in anyway. So a row without both is rejected with its own verdict rather
 * than silently defaulted to "imported".
 *
 * The operator supplies the consent source and date for the whole file when the
 * columns are absent, which is a deliberate, recorded act.
 */
class UniOneImportService
{
    /** Verdicts, one per row. */
    public const string ADDED = 'added';

    public const string DUPLICATE = 'duplicate';

    public const string INVALID = 'invalid';

    public const string SUPPRESSED = 'already_suppressed';

    public const string NO_CONSENT = 'missing_consent';

    /**
     * @param  list<array<string, string>>  $rows      parsed CSV rows, keyed by header
     * @param  array{email: string, name?: ?string, consent_source?: ?string, consent_at?: ?string}  $mapping
     * @param  array{consent_source?: ?string, consent_at?: ?string}  $fallback
     *
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function process(array $rows, array $mapping, array $fallback, bool $dryRun): array
    {
        $summary = [
            self::ADDED => 0, self::DUPLICATE => 0, self::INVALID => 0,
            self::SUPPRESSED => 0, self::NO_CONSENT => 0,
        ];
        $report = [];

        // Existing addresses fetched ONCE rather than per row: a 50,000-line
        // file would otherwise be 50,000 SELECTs.
        $emails = [];
        foreach ($rows as $row) {
            $candidate = mb_strtolower(trim((string) ($row[$mapping['email']] ?? '')));
            if ($candidate !== '') {
                $emails[$candidate] = true;
            }
        }

        $existing = UniOneReceiver::withTrashed()
            ->whereIn('email', array_keys($emails))
            ->get(['id', 'email', 'status', 'deleted_at'])
            ->keyBy(static fn (UniOneReceiver $r): string => mb_strtolower($r->email));

        // Dedup WITHIN the file too — the same address twice in one upload is a
        // duplicate on its second appearance, not two additions.
        $seen = [];
        $toInsert = [];
        $now = now();

        foreach ($rows as $index => $row) {
            $email = mb_strtolower(trim((string) ($row[$mapping['email']] ?? '')));
            $line = $index + 1;

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $summary[self::INVALID]++;
                $report[] = ['line' => $line, 'email' => $email, 'result' => self::INVALID, 'detail' => 'Not a valid address.'];

                continue;
            }

            if (isset($seen[$email])) {
                $summary[self::DUPLICATE]++;
                $report[] = ['line' => $line, 'email' => $email, 'result' => self::DUPLICATE, 'detail' => 'Repeated in this file.'];

                continue;
            }

            $seen[$email] = true;

            $match = $existing->get($email);

            if ($match !== null) {
                // An address already suppressed/bounced is reported as such
                // rather than as a plain duplicate: re-importing it must never
                // quietly reactivate an address UniOne told us to stop mailing.
                $isSuppressed = $match->status !== UniOneReceiver::STATUS_ACTIVE;
                $verdict = $isSuppressed ? self::SUPPRESSED : self::DUPLICATE;
                $summary[$verdict]++;
                $report[] = [
                    'line' => $line, 'email' => $email, 'result' => $verdict,
                    'detail' => $isSuppressed ? "Already on the list as {$match->status}." : 'Already on the list.',
                ];

                continue;
            }

            $consentSource = $this->value($row, $mapping['consent_source'] ?? null) ?: ($fallback['consent_source'] ?? '');
            $consentAtRaw = $this->value($row, $mapping['consent_at'] ?? null) ?: ($fallback['consent_at'] ?? '');
            $consentAt = $this->parseDate($consentAtRaw);

            if (trim($consentSource) === '' || $consentAt === null) {
                $summary[self::NO_CONSENT]++;
                $report[] = [
                    'line' => $line, 'email' => $email, 'result' => self::NO_CONSENT,
                    'detail' => 'No consent source or date — this address cannot be imported.',
                ];

                continue;
            }

            $summary[self::ADDED]++;
            $report[] = ['line' => $line, 'email' => $email, 'result' => self::ADDED, 'detail' => null];

            $toInsert[] = [
                'email'          => $email,
                'name'           => $this->value($row, $mapping['name'] ?? null) ?: null,
                'status'         => UniOneReceiver::STATUS_ACTIVE,
                'consent_source' => mb_substr($consentSource, 0, 120),
                'consent_at'     => $consentAt,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        // A dry run reports and writes nothing — the preview the brief asks for.
        if (! $dryRun && $toInsert !== []) {
            foreach (array_chunk($toInsert, 500) as $slice) {
                // insertOrIgnore, not insert: a concurrent import of the same
                // file would otherwise abort on the unique index halfway through.
                UniOneReceiver::query()->insertOrIgnore($slice);
            }
        }

        return ['summary' => $summary, 'rows' => $report];
    }

    /** @param array<string, string> $row */
    private function value(array $row, ?string $column): string
    {
        return $column === null || $column === '' ? '' : trim((string) ($row[$column] ?? ''));
    }

    private function parseDate(string $raw): ?Carbon
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }

        // A future consent date is a parsing accident, not a record of consent.
        return $parsed->isFuture() ? null : $parsed;
    }
}
