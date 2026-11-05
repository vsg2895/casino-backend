<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasSenderOverride;
use App\Mail\Contracts\SenderOverridable;
use App\Services\Mail\WarmupCustomContent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * A "Custom HTML" warmup message: the operator's own subject and HTML, sent as
 * typed, with the parts a sending provider would add around it.
 *
 *  - a plain-text alternative generated from the HTML ({@see WarmupCustomContent::plainText()}),
 *    so the message is multipart/alternative rather than HTML-only;
 *  - a `List-Unsubscribe` header pointing at the sending mailbox, which Gmail
 *    and Yahoo look for on anything that reads like marketing.
 *
 * Same From rule as every admin-originated email ({@see SenderOverridable}): the
 * authenticated mailbox's address, with the warmup site's name as the display
 * name — what a site template's own `from_name` does for the other templates.
 */
class WarmupCustomMessage extends Mailable implements SenderOverridable
{
    use HasSenderOverride;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly WarmupCustomContent $custom,
        public readonly ?string $fromName = null,
    ) {}

    public function envelope(): Envelope
    {
        $from = $this->fromAddressOverride === null
            ? null
            : new Address($this->fromAddressOverride, $this->fromName !== null && $this->fromName !== '' ? $this->fromName : null);

        return new Envelope(
            from: $from,
            subject: $this->custom->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.warmup.custom-text',
            with: ['bodyText' => $this->custom->plainText()],
            htmlString: $this->custom->html,
        );
    }

    public function headers(): Headers
    {
        $address = $this->fromAddressOverride;

        // Only with a real mailbox to point at. A mailto to nowhere is worse than none.
        if (! is_string($address) || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return new Headers();
        }

        return new Headers(text: [
            'List-Unsubscribe' => '<mailto:'.$address.'?subject=unsubscribe>',
        ]);
    }
}
