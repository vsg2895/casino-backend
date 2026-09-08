<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use App\Models\CasinoDetail;

/**
 * The column set for the operator-profile spreadsheet — THE single definition.
 *
 * Export writes it and import reads it, both through this class, so the file you
 * download and the file the importer expects cannot drift apart. Adding a
 * profile field means one entry here plus the migration; neither the exporter
 * nor the importer changes.
 *
 * Three cell conventions, all chosen so a human editing in Excel is not fighting
 * the format:
 *
 *  - LIST fields are comma-separated in one cell ("Visa, Skrill, Bitcoin").
 *  - TRISTATE fields accept yes/no/true/false/1/0, and a BLANK cell means "not
 *    checked" — which is a different claim from "no", and the difference is
 *    preserved end to end.
 *  - Everything else is plain text.
 *
 * A blank cell always means NULL, never "leave whatever is already stored".
 * That is the only coherent rule for a full-sheet round trip: the export hands
 * back the complete current picture, so what comes back IS the intended state,
 * and a blank that silently kept an old value would make clearing a wrong figure
 * impossible from the sheet.
 */
final class CasinoProfileSheet
{
    /** Written by the export, matched by the import. Slug is the row key. */
    public const string KEY_COLUMN = 'casino_slug';

    /**
     * Identifier columns. Read-only context for the editor — the importer keys
     * on the slug and ignores the rest, so renaming a casino in the sheet does
     * nothing and cannot corrupt anything.
     *
     * @return array<string, string>
     */
    public static function identifiers(): array
    {
        return [
            self::KEY_COLUMN => 'Casino slug (do not edit)',
            'casino_name'    => 'Casino name (do not edit)',
        ];
    }

    /**
     * Editable profile columns: field => [human heading, kind].
     *
     * Order is the order the columns appear in the file, grouped the way the
     * admin form and the public page group them, so an editor reading across the
     * sheet is reading the same story the visitor will.
     *
     * @return array<string, array{0: string, 1: 'text'|'int'|'list'|'tristate'}>
     */
    public static function fields(): array
    {
        return [
            // General
            'established_year'     => ['Established (year)', 'int'],
            'company'              => ['Operating company', 'text'],
            'licences'             => ['Licences (comma separated)', 'list'],
            'currencies'           => ['Currencies (comma separated)', 'list'],

            // Payments
            'payment_methods'      => ['Payment methods (comma separated)', 'list'],
            'min_deposit'          => ['Minimum deposit', 'text'],
            'min_withdrawal'       => ['Minimum withdrawal', 'text'],
            'withdrawal_limit'     => ['Withdrawal limit', 'text'],
            'pending_time'         => ['Pending time', 'text'],
            'withdrawal_time'      => ['Withdrawal time', 'text'],
            'verification_speed'   => ['Verification speed', 'text'],
            'deposit_fees'         => ['Deposit fees (yes/no)', 'tristate'],
            'withdrawal_fees'      => ['Withdrawal fees (yes/no)', 'tristate'],

            // Games
            'game_providers'       => ['Game providers (comma separated)', 'list'],
            'rng_tested'           => ['RNG tested (yes/no)', 'tristate'],
            'progressive_jackpots' => ['Progressive jackpots (yes/no)', 'tristate'],

            // Support
            'live_chat'            => ['Live chat (yes/no)', 'tristate'],
            'email_support'        => ['Email support (yes/no)', 'tristate'],
            'support_email'        => ['Support email', 'text'],
            'support_languages'    => ['Support languages (comma separated)', 'list'],

            // Safer play
            'tool_deposit_limit'   => ['Tool: deposit limit (yes/no)', 'tristate'],
            'tool_loss_limit'      => ['Tool: loss limit (yes/no)', 'tristate'],
            'tool_session_limit'   => ['Tool: session limit (yes/no)', 'tristate'],
            'tool_reality_check'   => ['Tool: reality check (yes/no)', 'tristate'],
            'tool_withdrawal_lock' => ['Tool: withdrawal lock (yes/no)', 'tristate'],
            'tool_self_exclusion'  => ['Tool: self-exclusion (yes/no)', 'tristate'],
        ];
    }

    /** @return list<string> Header row, identifiers first. */
    public static function headings(): array
    {
        return [
            ...array_values(self::identifiers()),
            ...array_map(static fn (array $spec): string => $spec[0], self::fields()),
        ];
    }

    /**
     * One export row for a casino, using its stored profile when it has one.
     *
     * @return list<string>
     */
    public static function row(string $slug, string $name, ?CasinoDetail $detail): array
    {
        $cells = [$slug, $name];

        foreach (self::fields() as $field => [, $kind]) {
            $cells[] = self::toCell($detail?->{$field}, $kind);
        }

        return $cells;
    }

    /** A stored value as the string that belongs in a cell. */
    private static function toCell(mixed $value, string $kind): string
    {
        if ($value === null) {
            return '';
        }

        return match ($kind) {
            'list'     => is_array($value) ? implode(', ', $value) : '',
            // Written back as the same words the heading asks for, so a
            // round-tripped file re-imports identically.
            'tristate' => $value ? 'yes' : 'no',
            default    => (string) $value,
        };
    }

    /**
     * Parse one sheet row into an attribute array for {@see CasinoDetail}.
     *
     * @param  array<string, string>  $cells  field => raw cell text
     * @return array<string, mixed>
     */
    public static function attributes(array $cells): array
    {
        $attributes = [];

        foreach (self::fields() as $field => [, $kind]) {
            $attributes[$field] = self::fromCell($cells[$field] ?? '', $kind);
        }

        return $attributes;
    }

    private static function fromCell(string $raw, string $kind): mixed
    {
        $value = trim($raw);

        // Blank always means NULL — see the class docblock for why this is the
        // only coherent rule for a full-sheet round trip.
        if ($value === '') {
            return null;
        }

        return match ($kind) {
            'int'  => ctype_digit($value) ? (int) $value : null,
            'list' => array_values(array_filter(
                array_map(static fn (string $part): string => trim($part), explode(',', $value)),
                static fn (string $part): bool => $part !== '',
            )),
            'tristate' => self::tristate($value),
            default    => $value,
        };
    }

    /**
     * Deliberately strict about what counts as an answer.
     *
     * Anything unrecognised becomes NULL ("not checked") rather than being
     * guessed at as false. On the safer-play columns a wrong guess would publish
     * "does not offer self-exclusion" about a real operator on the strength of a
     * typo.
     */
    private static function tristate(string $value): ?bool
    {
        return match (mb_strtolower($value)) {
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => null,
        };
    }
}
