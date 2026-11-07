<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Models\Site;

/**
 * What a NEWS post needs before search engines may index it.
 *
 * Google's spam policies name scraped content and scaled content abuse. A post
 * rewritten from a headline and a teaser adds nothing a reader could not get
 * from the source, so it stays `noindex` until an editor has added value and
 * unticks "Keep out of search results" — and the admin refuses that untick until:
 *
 * - the body has at least MIN_WORDS words, and
 * - it links to at least one casino, bonus, offer or category page of THIS
 *   site, which is the value this site can add that the source cannot.
 *
 * `news:rewrite` applies the same bar to what it saves: a rewrite that clears
 * it (the model is asked for 350-500 words and offered this site's pages to
 * link) is indexable, one that does not stays noindex for an editor.
 */
final class NewsIndexGate
{
    public const int MIN_WORDS = 300;

    /** Longest editor's note, in characters. */
    public const int EDITOR_NOTE_MAX = 2000;

    /** Site sections an internal link must point into. */
    private const string LINKED_SECTIONS = 'casinos|bonuses|categories|special-offers';

    /**
     * The editor's note counts toward the words (it is this site's own
     * writing) but not toward the link, which must be in the body.
     *
     * @return list<string> why the body cannot be indexed yet; empty when it can
     */
    public static function problems(?string $body, Site $site, ?string $editorNote = null): array
    {
        $problems = [];
        $words = self::wordCount((string) $body) + self::wordCount((string) $editorNote);

        if ($words < self::MIN_WORDS) {
            $problems[] = sprintf('the body and editor\'s note have %d words (at least %d)', $words, self::MIN_WORDS);
        }

        if (! self::hasInternalLink((string) $body, $site)) {
            $problems[] = 'it has no link to a casino, bonus or category page of this site';
        }

        return $problems;
    }

    public static function wordCount(string $body): int
    {
        $text = trim(html_entity_decode(strip_tags($body)));

        return $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);
    }

    /** A site path an internal link may count with: `/casinos/<slug>` and the like. */
    public static function isLinkablePath(string $path): bool
    {
        return preg_match('#^/(' . self::LINKED_SECTIONS . ')/[a-z0-9-]+/?$#i', $path) === 1;
    }

    /**
     * A relative `/casinos/…` style href, or an absolute one on this site's own
     * domain (with or without `www.`).
     */
    public static function hasInternalLink(string $body, Site $site): bool
    {
        if (preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/is', $body, $matches) === 0) {
            return false;
        }

        $domain = preg_replace('/^www\./i', '', mb_strtolower(trim((string) $site->domain)));

        foreach ($matches[2] as $href) {
            $href = html_entity_decode(trim($href));
            $parts = parse_url($href);

            if ($parts === false) {
                continue;
            }

            if (isset($parts['host'])) {
                $host = preg_replace('/^www\./i', '', mb_strtolower($parts['host']));

                if ($domain === '' || $host !== $domain) {
                    continue;
                }
            } elseif (! str_starts_with($href, '/')) {
                continue;
            }

            if (self::isLinkablePath($parts['path'] ?? '')) {
                return true;
            }
        }

        return false;
    }
}
