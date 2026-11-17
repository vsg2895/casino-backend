<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasSenderOverride;
use App\Mail\Contracts\SenderOverridable;
use App\Support\Mail\CustomEmailContent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * A scheduled "Custom HTML" campaign: the operator's own subject and HTML, sent
 * as typed, with the parts a sending provider would add around it.
 *
 *  - a plain-text alternative generated from the HTML, so the message is
 *    multipart/alternative rather than HTML-only;
 *  - RFC 8058 one-click unsubscribe headers, when the recipient HAS a real
 *    opt-out URL — which, unlike warmup's seed mailboxes, a scheduled campaign
 *    always does: a subscriber's own token, or the receiver list's.
 *
 * The From pair is the schedule's own, because this template has no stored site
 * row to take one from. {@see \App\Models\EmailSchedule::customFromAddress()}
 * carries the warning that goes with that freedom.
 *
 * Deliberately NOT a sibling of {@see WarmupCustomMessage}: that one addresses
 * seed mailboxes and can only offer a `mailto:` opt-out, while this one is sent
 * to real recipients who must be able to unsubscribe for real. The two differ
 * in exactly the part that matters, so they stay separate classes.
 */
class ScheduleCustomMessage extends Mailable implements SenderOverridable
{
    use HasSenderOverride;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly CustomEmailContent $custom,
        public readonly ?string $fromName = null,
        /** The visible opt-out URL, and the POST target of the one-click header. */
        public readonly string $unsubscribeUrl = '',
        public readonly string $oneClickUrl = '',
    ) {}

    public function envelope(): Envelope
    {
        // Null leaves the mailer's configured From in place — the same rule
        // every other admin-originated email follows when no override is set.
        $from = $this->fromAddressOverride === null
            ? null
            : new Address(
                $this->fromAddressOverride,
                $this->fromName !== null && $this->fromName !== '' ? $this->fromName : null,
            );

        return new Envelope(from: $from, subject: $this->custom->subject);
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.schedule.custom-text',
            with: [
                'bodyText'       => $this->custom->plainText(),
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ],
            htmlString: $this->bodyHtml(),
        );
    }

    /**
     * RFC 8058 one-click unsubscribe, so Gmail/Yahoo/Apple show their native
     * "Unsubscribe" button instead of letting the recipient reach for "report
     * spam". Only with a real URL behind it — a header pointing nowhere is
     * worse than none.
     */
    public function headers(): Headers
    {
        return new Headers(
            text: $this->oneClickUrl === '' ? [] : [
                'List-Unsubscribe'      => '<' . $this->oneClickUrl . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        );
    }

    /**
     * The typed HTML, with a visible opt-out appended when the body does not
     * already carry one.
     *
     * A bulk message with no visible way out is what gets a sending domain
     * blocked, and the admin pasting the HTML cannot be relied on to include
     * one. The check is deliberately crude — it looks for the URL itself, or
     * for the word in a link — because the cost of a false positive is a
     * missing footer and the cost of a false negative is one extra line.
     *
     * NOT called html(): Laravel's own Mailable declares a public html(), and
     * a private override of it is a fatal error at class-load time.
     */
    private function bodyHtml(): string
    {
        if ($this->unsubscribeUrl === '' || $this->carriesOptOut()) {
            return $this->custom->html;
        }

        $url = e($this->unsubscribeUrl);

        $footer = '<div style="margin:24px 0 8px;padding-top:16px;border-top:1px solid #e5e7eb;'
            . 'font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:#6b7280;text-align:center">'
            . 'You are receiving this because you subscribed. '
            . '<a href="' . $url . '" style="color:#6b7280;text-decoration:underline">Unsubscribe</a>.'
            . '</div>';

        // Inside </body> when there is one, so the footer stays within the
        // document rather than trailing after it.
        if (preg_match('#</body\s*>#i', $this->custom->html) === 1) {
            return (string) preg_replace('#</body\s*>#i', $footer . '</body>', $this->custom->html, 1);
        }

        return $this->custom->html . $footer;
    }

    /** Whether the typed HTML already offers a way out. */
    private function carriesOptOut(): bool
    {
        if (str_contains($this->custom->html, $this->unsubscribeUrl)) {
            return true;
        }

        return preg_match('#<a\b[^>]*>[^<]*\bunsubscribe\b#i', $this->custom->html) === 1;
    }
}
