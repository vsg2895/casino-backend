<?php

declare(strict_types=1);

namespace App\Support\Forum;

/**
 * Sanitisation for everything a visitor can put in the forum.
 *
 * ── Member posts are PLAIN TEXT, and that is a deliberate refusal ───────────
 *
 * The brief allows a strict HTML allow-list. An allow-list is a permanent
 * commitment to out-parsing an attacker: every release of every browser adds
 * another way to smuggle script through markup that looked inert — `javascript:`
 * in an href, `data:` URLs, mutation XSS where the sanitised string re-parses
 * differently once it is inside the DOM, SVG payloads, CSS expressions.
 *
 * A gambling forum is a high-value target for exactly this: session theft there
 * is worth money. Nobody replying to a thread needs a table or an image, so the
 * feature being protected is worth far less than the hole being opened. Posts
 * are stored as text, rendered as text, and the class of bug does not exist.
 *
 * Admin article bodies DO carry HTML — different authors, different threat
 * model, and still filtered against a short allow-list below, because an admin
 * account can be compromised too.
 */
final class ForumContent
{
    public const int MAX_POST_LENGTH = 8_000;

    /** Tags an admin may use in an article body. Deliberately short. */
    private const ARTICLE_TAGS = '<p><br><strong><em><b><i><u><ul><ol><li><h2><h3><h4><blockquote><a><code><pre>';

    /**
     * Normalise a member's post.
     *
     * Strips every tag, decodes entities ONCE so `&lt;script&gt;` cannot be
     * stored pre-escaped and then double-decoded downstream, collapses runs of
     * blank lines, and trims. The result is text; whoever renders it escapes it.
     */
    public static function post(string $body): string
    {
        // Decode first, then strip. The other order lets "&lt;script&gt;alert(1)
        // &lt;/script&gt;" survive strip_tags untouched and become live markup
        // the moment anything decodes it.
        $text = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);

        // Control characters, except tab and newline. A zero-width or
        // bidi-override character in a display context is a spoofing tool.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = preg_replace('/\x{200B}|\x{200E}|\x{200F}|\x{202A}-\x{202E}/u', '', $text) ?? $text;

        // At most one blank line between paragraphs.
        $text = preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", $text)) ?? $text;

        return mb_substr(trim($text), 0, self::MAX_POST_LENGTH);
    }

    /** Filter an admin-authored article body against the allow-list. */
    public static function articleBody(string $html): string
    {
        $clean = strip_tags($html, self::ARTICLE_TAGS);

        // An allow-listed <a> is still a vector: javascript:, data: and vbscript:
        // hrefs are the oldest trick in the file and survive a tag allow-list
        // untouched.
        $clean = preg_replace('/\shref\s*=\s*(["\'])\s*(?:javascript|data|vbscript):[^"\']*\1/i', ' href="#"', $clean) ?? $clean;

        // Event handlers, in case a tag arrived with attributes.
        $clean = preg_replace('/\son[a-z]+\s*=\s*(["\']).*?\1/is', '', $clean) ?? $clean;

        return trim($clean);
    }

    /**
     * Whether a post contains anything link-shaped.
     *
     * Deliberately broad. It catches bare domains and obfuscations like
     * "example(dot)com" and "hxxp://", because a spammer posting a link has
     * every reason to disguise it and a genuine new member has none. A false
     * positive costs an untrusted member one trip through the moderation queue,
     * which is where their first posts were going anyway.
     */
    public static function containsLink(string $text): bool
    {
        $needles = [
            '~https?://~i',
            '~\bwww\.~i',
            '~hxxps?://~i',
            '~\[url~i',
            // "example . com", "example(dot)com", "example[dot]com"
            '~\b[a-z0-9-]+\s*(?:\(|\[)?\s*(?:\.|dot)\s*(?:\)|\])?\s*(?:com|net|org|io|xyz|ru|cn|info|biz|top|live|casino|bet)\b~i',
        ];

        foreach ($needles as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
