<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\WarmupSend;

/**
 * The subject and HTML body of a "Custom HTML" warmup run.
 *
 * Typed by the operator in the send dialog instead of choosing a site's stored
 * template, kept on the run row (`warmup_sends.custom_subject` / `custom_html`)
 * and read back by every batch of that run — so a batch re-queued minutes later
 * sends exactly what was previewed, and the queue payload stays a list of
 * addresses rather than a copy of the HTML per job.
 *
 * The HTML is sent AS TYPED. No rewriting, so the preview is the message. What
 * this class adds is what a sending provider (SendGrid, Brevo) adds around a
 * raw HTML campaign: a generated plain-text part — HTML-only mail is a spam
 * signal of its own — and a pre-send check of the things that hurt delivery.
 */
final readonly class WarmupCustomContent
{
    public function __construct(
        public string $subject,
        public string $html,
    ) {}

    /** The run's custom content, or null when the run is not a custom one or the row lacks it. */
    public static function fromSend(?WarmupSend $send): ?self
    {
        if ($send === null || ! is_string($send->custom_subject) || ! is_string($send->custom_html)) {
            return null;
        }

        if (trim($send->custom_subject) === '' || trim($send->custom_html) === '') {
            return null;
        }

        return new self($send->custom_subject, $send->custom_html);
    }

    /** Size of the HTML part on the wire, in bytes. */
    public function htmlBytes(): int
    {
        return strlen($this->html);
    }

    /**
     * The plain-text alternative, derived from the HTML.
     *
     * Block boundaries become line breaks, a link keeps its address beside its
     * text, and head/style/script content is dropped rather than leaking CSS
     * into the text part. Deliberately simple: it only has to read well, and a
     * text part that roughly mirrors the HTML is what filters look for.
     */
    public function plainText(): string
    {
        $html = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', $this->html) ?? $this->html;

        // <a href="url">text</a> → "text (url)", unless the text already is the url.
        $html = preg_replace_callback(
            '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $text = trim(strip_tags($m[3]));
                $url = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if ($url === '' || str_starts_with($url, '#') || $text === $url) {
                    return $m[3];
                }

                return $text === '' ? $url : "{$text} ({$url})";
            },
            $html,
        ) ?? $html;

        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|table|h[1-6]|li|ul|ol|blockquote|section|article|header|footer)>#i', "\n\n", $html) ?? $html;
        $html = preg_replace('#<li\b[^>]*>#i', '- ', $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * What a provider's pre-send check would flag, in plain words. Advisory: none
     * of these blocks a send.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];
        $clipAt = (int) config('warmup.custom.clip_warning_bytes', 102_000);

        if ($this->htmlBytes() > $clipAt) {
            $warnings[] = sprintf(
                'The HTML is %s KB. Gmail clips messages over about %s KB behind a "View entire message" link.',
                number_format($this->htmlBytes() / 1000, 1),
                number_format($clipAt / 1000),
            );
        }

        if (preg_match('#<script\b#i', $this->html) === 1) {
            $warnings[] = 'Contains <script>. Mail clients never run it, and spam filters score it heavily.';
        }

        if (preg_match('#<(form|iframe|embed|object)\b#i', $this->html) === 1) {
            $warnings[] = 'Contains a form, iframe or embed. Most clients strip them and filters penalise them.';
        }

        if (preg_match('#\b(src|href)\s*=\s*["\']http://#i', $this->html) === 1) {
            $warnings[] = 'Some links or images use http:// rather than https://.';
        }

        if (mb_strlen($this->plainText()) < 50) {
            $warnings[] = 'Very little text. An image-only or near-empty message reads as spam; add some real copy.';
        }

        if (preg_match('#<img\b(?![^>]*\balt\s*=)[^>]*>#i', $this->html) === 1) {
            $warnings[] = 'Some images have no alt text, so they show as blank boxes where images are blocked.';
        }

        return $warnings;
    }
}
