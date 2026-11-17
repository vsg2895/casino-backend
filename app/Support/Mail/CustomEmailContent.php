<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Services\Mail\WarmupCustomContent;

/**
 * The subject and HTML body of a "Custom HTML" send — an operator's own email,
 * typed into a schedule instead of chosen from the site's stored templates.
 *
 * The HTML is sent AS TYPED. No rewriting, so what the admin pasted is what
 * lands. What this adds is what a sending provider adds around a raw HTML
 * campaign: a generated plain-text alternative — HTML-only mail is a spam
 * signal of its own — and the same pre-send warnings the warmup dialog shows.
 *
 * WHY IT DELEGATES. {@see WarmupCustomContent} already implements the HTML to
 * text conversion and the pre-send checks, and they are not warmup-specific:
 * they are this project's answer to "turn pasted HTML into a sendable
 * message". Copying sixty lines of regex into a second class would give the two
 * copies somewhere to drift apart, and the next fix would land in one of them.
 * So the logic stays where it already lives and this value object wraps it,
 * which leaves warmup's own behaviour untouched.
 */
final readonly class CustomEmailContent
{
    private function __construct(
        public string $subject,
        public string $html,
        private WarmupCustomContent $content,
    ) {}

    /**
     * The content of a custom send, or null when either half is missing — a
     * schedule in that state must send nothing rather than an empty email.
     */
    public static function make(?string $subject, ?string $html): ?self
    {
        if (! is_string($subject) || ! is_string($html)) {
            return null;
        }

        if (trim($subject) === '' || trim($html) === '') {
            return null;
        }

        return new self($subject, $html, new WarmupCustomContent($subject, $html));
    }

    /** Size of the HTML part on the wire, in bytes. */
    public function htmlBytes(): int
    {
        return $this->content->htmlBytes();
    }

    /** The plain-text alternative, derived from the HTML. */
    public function plainText(): string
    {
        return $this->content->plainText();
    }

    /**
     * What a provider's pre-send check would flag, in plain words. Advisory:
     * none of these blocks a send.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->content->warnings();
    }
}
