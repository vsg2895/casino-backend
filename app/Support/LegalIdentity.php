<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Site;

/**
 * A site's legal identity, substituted into CMS pages when they are SERVED.
 *
 * ── Why tokens instead of text replacement ──────────────────────────────────
 *
 * Seeded legal pages used to carry bracketed placeholders that a command
 * replaced with real values inside the stored HTML. That made the stored copy
 * the only record of who operates the site, so a wrong value (a postcode given
 * as the company name) was baked into three pages and could only be fixed by
 * hand. Pages now store `{{legal_entity_name}}` and friends; the values live on
 * the site row, are edited once in the admin, and reach every page, the footer
 * and the Organization JSON-LD together.
 *
 * ── An empty value hides its line ───────────────────────────────────────────
 *
 * Nothing here may ever print a placeholder or an empty "operated by ." to a
 * reader. When a value is missing:
 *
 *  1. any element carrying `data-requires="token another"` is removed whole
 *     when one of those tokens is empty — for a heading whose section only
 *     makes sense with the value ("Postal Address"), or an inline span around
 *     the clause that names it. Such elements must not nest inside an element
 *     of the same tag name: matching is by regular expression, which keeps the
 *     stored HTML byte-for-byte untouched everywhere else;
 *  2. any remaining occurrence removes the SENTENCE containing it, inside its
 *     closest block (p, li, h1–h6, dt, dd, td, blockquote); a block left with
 *     no text is removed;
 *  3. a stray token outside any block is simply dropped.
 *
 * Unknown `{{tokens}}` are treated as empty for the same reason: a typo in the
 * admin must not reach the public page as literal braces.
 */
final class LegalIdentity
{
    /** Every token a page may use, each backed by a `sites` column of the same name. */
    public const array TOKENS = [
        'legal_entity_name',
        'registered_address',
        'company_number',
        'governing_law',
        'contact_email',
        'support_email',
        'privacy_email',
    ];

    /**
     * The fields the admin warns about while empty. `company_number` is
     * optional by nature — not every operator is a registered company — and
     * the e-mail addresses fall back on one another.
     */
    public const array REQUIRED = ['legal_entity_name', 'registered_address', 'contact_email'];

    private const string TOKEN_PATTERN = '/\{\{\s*([a-z_]+)\s*\}\}/';

    private const string BLOCK_TAGS = 'p|li|h[1-6]|dt|dd|td|blockquote';

    /**
     * Token values for a site, trimmed; empty string when unset.
     *
     * `support_email` and `privacy_email` fall back to `contact_email`: a site
     * with one inbox should not lose the support line on every page for want of
     * typing the same address three times. The reverse does not apply — the
     * general contact address is never guessed from a specialised one.
     *
     * @return array<string, string>
     */
    public static function valuesFor(Site $site): array
    {
        $values = [];

        foreach (self::TOKENS as $token) {
            $values[$token] = self::clean($site->getAttribute($token));
        }

        foreach (['support_email', 'privacy_email'] as $token) {
            if ($values[$token] === '') {
                $values[$token] = $values['contact_email'];
            }
        }

        return $values;
    }

    /**
     * The REQUIRED fields this site has left empty, for the admin's warning.
     *
     * @return list<string>
     */
    public static function missingFor(Site $site): array
    {
        return array_values(array_filter(
            self::REQUIRED,
            static fn (string $token): bool => self::clean($site->getAttribute($token)) === '',
        ));
    }

    /**
     * The identity as public data, null for every empty field — for the
     * footer and the Organization JSON-LD, which must hide a line rather than
     * print nothing after a label.
     *
     * @return array<string, ?string>
     */
    public static function publicFor(Site $site): array
    {
        return array_map(
            static fn (string $value): ?string => $value === '' ? null : $value,
            self::valuesFor($site),
        );
    }

    /**
     * Substitute the tokens in a page body.
     *
     * @param  array<string, string>  $values  token => value; missing keys count as empty
     */
    public static function render(?string $html, array $values): string
    {
        $html = (string) $html;

        if (! str_contains($html, '{{') && ! str_contains($html, 'data-requires')) {
            return $html;
        }

        $isEmpty = static fn (string $token): bool => trim($values[$token] ?? '') === '';

        // 1. Elements that declare what they need. Repeated so a marked span
        //    inside a marked paragraph is reached once the paragraph has been
        //    kept (a pass consumes the outer element whole).
        for ($pass = 0; $pass < 5 && str_contains($html, 'data-requires'); $pass++) {
            $html = self::applyRequires($html, $isEmpty);
        }

        // 2. Sentences that name an empty value, inside their block.
        $html = (string) preg_replace_callback(
            '/<(' . self::BLOCK_TAGS . ')\b([^>]*)>(.*?)<\/\1>/is',
            static function (array $m) use ($isEmpty): string {
                if (! self::mentionsEmptyToken($m[3], $isEmpty)) {
                    return $m[0];
                }

                $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z<"“(])/u', $m[3]) ?: [$m[3]];
                $kept = array_filter(
                    $sentences,
                    static fn (string $sentence): bool => ! self::mentionsEmptyToken($sentence, $isEmpty),
                );
                $inner = trim(implode(' ', $kept));

                if (trim(html_entity_decode(strip_tags($inner))) === '') {
                    return '';
                }

                return '<' . $m[1] . $m[2] . '>' . $inner . '</' . $m[1] . '>';
            },
            $html,
        );

        // 3. Whatever is left: real values in, strays out.
        return (string) preg_replace_callback(
            self::TOKEN_PATTERN,
            static fn (array $m): string => $isEmpty($m[1])
                ? ''
                : htmlspecialchars(trim($values[$m[1]]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $html,
        );
    }

    /** @param callable(string): bool $isEmpty */
    private static function applyRequires(string $html, callable $isEmpty): string
    {
        return (string) preg_replace_callback(
            '/<([a-z][a-z0-9]*)\b([^>]*?)\sdata-requires\s*=\s*"([^"]*)"([^>]*)>(.*?)<\/\1>/is',
            static function (array $m) use ($isEmpty): string {
                foreach (preg_split('/[\s,]+/', trim($m[3])) ?: [] as $token) {
                    if ($token !== '' && $isEmpty($token)) {
                        return '';
                    }
                }

                // Satisfied: keep the element and drop the marker, which means
                // nothing to a reader.
                return '<' . $m[1] . $m[2] . $m[4] . '>' . $m[5] . '</' . $m[1] . '>';
            },
            $html,
        );
    }

    /** @param callable(string): bool $isEmpty */
    private static function mentionsEmptyToken(string $html, callable $isEmpty): bool
    {
        if (preg_match_all(self::TOKEN_PATTERN, $html, $matches) === 0) {
            return false;
        }

        foreach ($matches[1] as $token) {
            if ($isEmpty($token)) {
                return true;
            }
        }

        return false;
    }

    /** One line, trimmed: an address typed over several lines reads as one. */
    private static function clean(mixed $value): string
    {
        $text = trim((string) $value);

        return (string) preg_replace('/\s*[\r\n]+\s*/', ', ', $text);
    }
}
