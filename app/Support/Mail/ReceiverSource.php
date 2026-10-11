<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * Whose list a Mailgun receiver came from.
 *
 * TWO values, and the distinction is the one that decides how an address may be
 * mailed:
 *
 *   our_clients       people who reached us — signed up, asked, were already
 *                     customers. Every address on the list before this field
 *                     existed is one of these, which is what the backfill in
 *                     2026_11_04_100000 says.
 *   external_clients  addresses that came from somewhere else — a partner list,
 *                     a purchased file, an export. Colder, and the reason a
 *                     credential needs to be able to mail one group without the
 *                     other.
 *
 * A CLOSED set rather than free text. The field is a send-time filter, and free
 * text splits an audience on a typo: "Facebook ads" and "facebook ads" would be
 * two lists, one of which never gets mailed and nobody notices. Two fixed values
 * can be put in a <select>, checked by the database, and matched exactly.
 *
 * Not to be confused with `mailgun_receivers.source` (`import` / `manual`),
 * which records HOW the row was created rather than whose it is.
 */
enum ReceiverSource: string
{
    case OurClients = 'our_clients';
    case ExternalClients = 'external_clients';

    /** The value every pre-existing address is backfilled to. */
    public const string DEFAULT = self::OurClients->value;

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** Human wording for the admin — the enum owns its own labels. */
    public function label(): string
    {
        return match ($this) {
            self::OurClients => 'Our clients',
            self::ExternalClients => 'External clients',
        };
    }

    /**
     * Parse loosely, for input that may be a case, a string, or nothing.
     *
     * Returns null for anything unrecognised rather than throwing: a stale
     * admin bundle posting a removed value should fail validation with a field
     * error, not a 500.
     */
    public static function parse(self|string|null $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        $value = trim((string) $value);

        return $value === '' ? null : self::tryFrom($value);
    }
}
