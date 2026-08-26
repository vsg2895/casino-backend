<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The clock context every scheduled sweep logs, so "why did this not fire?" can
 * be answered from the log alone.
 *
 * WHY THIS EXISTS. Timing bugs here get reported as a mismatch between two
 * numbers on two screens: a timestamp in the ADMIN and a timestamp in
 * laravel.log. They are rendered in different timezones and neither says so,
 * which makes an ordinary display offset look identical to a broken clock:
 *
 *   - The log is written in `config('app.timezone')` — UTC here.
 *   - The admin renders with `new Date(iso).toLocaleString()`, i.e. in the
 *     VIEWER'S BROWSER timezone. From Yerevan (UTC+4) a row stored at 12:49 UTC
 *     reads "16:49" — a four-hour gap that is pure presentation.
 *
 * Everything the eligibility rule touches — `verified_at`, the sweep's cutoff,
 * the claim — is written and compared through `Carbon::now()`, so it is all one
 * clock and neither the browser's zone nor the database session's zone can
 * shift it. What WOULD shift it is the two machines disagreeing about the
 * actual instant, so that — and only that — is what `drift_seconds` measures:
 * both sides are asked for UTC, which cancels the session timezone out.
 *
 * `db_timezone` is carried alongside purely as information. A database session
 * running at +04:00 is normal and harmless here; it would only start to matter
 * if a query ever took a time from the DATABASE (`NOW()`, `CURRENT_TIMESTAMP`,
 * a `useCurrent()` column default) instead of from PHP. Nothing in the
 * promotion path does.
 *
 * Read-only and non-fatal: a database that cannot answer must never take down
 * the sweep that was about to run.
 */
final class ClockFacts
{
    /**
     * Genuine skew beyond this many seconds is worth a human looking at it.
     * Generous: a round trip plus a second-resolution DB clock is worth a
     * second or two on its own, and the delays being measured are minutes.
     */
    public const int DRIFT_TOLERANCE_SECONDS = 30;

    /**
     * @return array{now: string, timezone: string, db_now_utc: string, db_timezone: string, drift_seconds: int|null}
     */
    public static function forLog(): array
    {
        $clock = self::databaseClock();

        return [
            // Same timezone this log line's own timestamp is written in, so the
            // two always agree and the entry is self-describing.
            'now'         => Carbon::now()->toDateTimeString(),
            'timezone'    => (string) config('app.timezone', 'UTC'),
            'db_now_utc'  => $clock['utc']?->toDateTimeString() ?? 'unavailable',
            'db_timezone' => $clock['timezone'] ?? 'unavailable',
            // Null when the database could not be asked — absent evidence, not
            // evidence of agreement.
            'drift_seconds' => $clock['utc'] === null
                ? null
                : (int) round(Carbon::now('UTC')->diffInSeconds($clock['utc'], absolute: true)),
        ];
    }

    /**
     * The database server's own UTC instant and its session timezone.
     *
     * UTC on purpose: comparing against `CURRENT_TIMESTAMP` would fold the
     * session's timezone into the result and report a routine +04:00 session as
     * four hours of clock skew.
     *
     * @return array{utc: Carbon|null, timezone: string|null}
     */
    private static function databaseClock(): array
    {
        try {
            $row = DB::selectOne('select UTC_TIMESTAMP() as utc_now, @@session.time_zone as tz');

            return $row === null
                ? ['utc' => null, 'timezone' => null]
                : ['utc' => Carbon::parse($row->utc_now, 'UTC'), 'timezone' => (string) $row->tz];
        } catch (Throwable) {
            // Deliberately swallowed, and deliberately broad: this is diagnostic
            // garnish on a log line, and the syntax is MySQL-specific — SQLite
            // (the test connection) simply has nothing to answer with. It has no
            // business failing the command that was about to run.
            return ['utc' => null, 'timezone' => null];
        }
    }
}
