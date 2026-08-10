<?php

declare(strict_types=1);

namespace App\Support\Phone;

/**
 * Normalises phone numbers to E.164 — THE single definition of "what a stored
 * number looks like".
 *
 * Every write path goes through {@see normalise()}: the admin form, the
 * spreadsheet import and the send-time lookup. That is not a style preference.
 * The list's uniqueness guarantee is a `unique` index on a varchar, so
 * "+1 555 010 0199", "(555) 010-0199" and "+15550100199" are three different
 * rows unless something reduces them to one form first — and a duplicate row
 * means a subscriber receives the same SMS twice, billed twice.
 *
 * Why not libphonenumber: it would be the better tool, but adding a Composer
 * dependency is not something this change can install. The rules below are
 * deliberately conservative instead — they reject anything ambiguous rather than
 * guessing, so the failure mode is "the admin is told a row was invalid", not
 * "a real message went to a real stranger".
 */
final class PhoneNumber
{
    /** E.164 allows at most 15 digits; ITU sets 7 as a realistic floor. */
    private const int MIN_DIGITS = 7;
    private const int MAX_DIGITS = 15;

    /**
     * A number in E.164 ("+15551234567"), or null when it cannot be made into
     * one with certainty.
     *
     * Accepts the shapes spreadsheets actually contain — spaces, dashes,
     * parentheses, dots, a leading "00" international prefix, or a value Excel
     * has helpfully turned into a float ("15550100199.0").
     */
    public static function normalise(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        // Reject extension notation outright — "555-123-4567 ext 22", "555x22",
        // "5551234567,22".
        //
        // Rejecting rather than truncating, deliberately. Stripping the
        // punctuation would have folded the extension digits into the number and
        // produced "+1555123456722" — a wrong number that is the right LENGTH, so
        // it passes every remaining check and gets a real message sent to a real
        // stranger. Keeping only the part before the marker would be a guess
        // instead. And an extension implies a landline PBX, which cannot receive
        // SMS at all, so the row was never a usable recipient: far better it lands
        // in the import's `invalid` count where an admin can see it.
        if (preg_match('/(?:ext\.?|extn|[x#,;])\s*\d/i', $raw) === 1) {
            return null;
        }

        // Excel stores a long numeric cell as a float and hands it back as
        // "1.5550100199E+10" or "15550100199.0". Recover the integer form rather
        // than discarding a row that is perfectly valid on screen.
        $raw = self::undoScientificNotation($raw);

        // Remember whether the caller was explicit about this being
        // international BEFORE stripping punctuation, because "+" is the only
        // thing that distinguishes a full number from a national one.
        $explicitInternational = str_starts_with($raw, '+') || str_starts_with($raw, '00');

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        // "00" is the international access prefix in most of the world; once
        // removed, what follows is the country code.
        if (! str_starts_with($raw, '+') && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (! $explicitInternational) {
            $digits = self::applyDefaultCountryCode($digits);

            if ($digits === null) {
                // No country code and no configured default: refuse to guess.
                return null;
            }
        }

        $length = strlen($digits);

        if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS) {
            return null;
        }

        // A country code never starts with 0, so a leading zero here means the
        // value was a national number that slipped past the checks above.
        if (str_starts_with($digits, '0')) {
            return null;
        }

        return '+' . $digits;
    }

    /** Whether a value is already, or can become, a valid E.164 number. */
    public static function isValid(mixed $value): bool
    {
        return self::normalise($value) !== null;
    }

    /**
     * A partially masked number for display in logs and history where the full
     * value is not needed, e.g. "+1555•••0199".
     */
    public static function mask(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 8) {
            return $phone;
        }

        return substr($phone, 0, 5) . '•••' . substr($phone, -4);
    }

    /**
     * Complete a national number using config('sms.default_country_code'),
     * or null when there is no default to apply.
     */
    private static function applyDefaultCountryCode(string $digits): ?string
    {
        $code = preg_replace('/\D+/', '', (string) config('sms.default_country_code', '')) ?? '';

        if ($code === '') {
            return null;
        }

        // Strip the national trunk prefix ("07700…" → "7700…") before prepending
        // the country code, or the result carries a spurious zero.
        if (config('sms.strip_national_prefix', true) && str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0');
        }

        if ($digits === '') {
            return null;
        }

        // Already carries the country code (a list mixing "447700900123" and
        // "07700900123" is common) — prefixing again would corrupt it.
        if (str_starts_with($digits, $code)) {
            return $digits;
        }

        return $code . $digits;
    }

    /**
     * Recover the digits from a value Excel has rendered in scientific notation
     * or given a trailing ".0". Anything else is returned untouched.
     */
    private static function undoScientificNotation(string $raw): string
    {
        if (preg_match('/^\+?\d+(\.\d+)?[Ee]\+?\d+$/', $raw) === 1) {
            $sign = str_starts_with($raw, '+') ? '+' : '';

            return $sign . number_format((float) $raw, 0, '.', '');
        }

        if (preg_match('/^(\+?\d+)\.0+$/', $raw, $matches) === 1) {
            return $matches[1];
        }

        return $raw;
    }
}
