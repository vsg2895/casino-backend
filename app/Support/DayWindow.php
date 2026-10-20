<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Turns a CALENDAR DATE chosen by a human into the UTC instants that bound it.
 *
 * ── The bug this exists to kill ─────────────────────────────────────────────
 *
 * An admin date picker hands back a calendar day in the operator's own
 * timezone — "2026-10-20" means *their* 20th. The database stores instants in
 * UTC. Passing that string straight into a `where` makes MySQL read it as
 * `2026-10-20 00:00:00 UTC`, which for an operator in UTC+3 is 03:00 on their
 * 20th: the first three hours of their day are silently outside their own
 * filter.
 *
 * That is exactly what made the UniOne receivers screen unreadable. Mail handed
 * over at 21:30 UTC on the 19th is 00:30 on the 20th in UTC+3, so the row
 * rendered "20 Oct" — `toLocaleDateString` converts to local — while the filter
 * for the 20th found nothing and the filter for the 19th found everything. The
 * screen contradicted itself, and neither half was wrong on its own terms.
 *
 * ── The rule ────────────────────────────────────────────────────────────────
 *
 * A day is `[00:00:00, 23:59:59]` IN THE OPERATOR'S ZONE, converted to UTC.
 * `endOfDay()` rather than a literal `' 23:59:59'` string: the columns are
 * second-precision `timestamp`s today, but a future migration to
 * `timestamp(3)` would make a hand-built string quietly exclude the last
 * second of every window, and nobody would look here for it.
 *
 * DST is handled by Carbon rather than by arithmetic — on a spring-forward day
 * the local midnight may not exist, and an offset computed once and reused is
 * wrong for half the year. That is also why this takes an IANA NAME
 * ("Europe/Tallinn") and not a numeric offset.
 *
 * ── Failing safe ────────────────────────────────────────────────────────────
 *
 * An absent, empty or unrecognised zone falls back to UTC — the behaviour every
 * caller had before this class existed. A browser sending a zone name PHP does
 * not know (or no zone at all) must narrow nobody's results to zero; it just
 * stops getting the local-day correction.
 */
final class DayWindow
{
    /**
     * The first instant of `$date` in `$timezone`, as UTC.
     *
     * Returns null for a null/blank date so callers can feed it straight into
     * a conditional `when()` without a second null check.
     */
    public static function startUtc(?string $date, ?string $timezone): ?Carbon
    {
        return self::bound($date, $timezone, start: true);
    }

    /** The last instant of `$date` in `$timezone`, as UTC. */
    public static function endUtc(?string $date, ?string $timezone): ?Carbon
    {
        return self::bound($date, $timezone, start: false);
    }

    /**
     * Is this a timezone PHP can resolve?
     *
     * Used by the request validation so a bad value is rejected at the edge
     * rather than silently ignored here — the two behave the same, but a
     * validation error is visible and a silent fallback is not.
     */
    public static function isValidTimezone(string $timezone): bool
    {
        if (trim($timezone) === '') {
            return false;
        }

        try {
            new \DateTimeZone($timezone);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function bound(?string $date, ?string $timezone, bool $start): ?Carbon
    {
        $date = trim((string) $date);

        if ($date === '') {
            return null;
        }

        $zone = ($timezone !== null && self::isValidTimezone($timezone)) ? $timezone : 'UTC';

        try {
            // `!Y-m-d` zeroes the time fields, so a value that arrives with a
            // time attached cannot drag the boundary off midnight.
            $day = Carbon::createFromFormat('!Y-m-d', $date, $zone);
        } catch (Throwable) {
            // Unparseable: let the caller behave as if no bound was given,
            // rather than inventing one. Validation should have caught it.
            return null;
        }

        return ($start ? $day->startOfDay() : $day->endOfDay())->utc();
    }
}
