<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasSenderOverride;
use App\Mail\Contracts\SenderOverridable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A warmup message.
 *
 * Deliberately plain text: warmup exists to generate ordinary, conversational
 * traffic from the sending mailbox so its reputation builds. Marketing-shaped
 * HTML, tracking pixels and unsubscribe footers are exactly the signals that get
 * a young sender filtered, so none are used here.
 *
 * Implements {@see SenderOverridable} so it goes through the same From-address
 * rule as every other admin-originated email: the authenticated .env mailbox.
 */
class WarmupEmailMessage extends Mailable implements SenderOverridable
{
    use HasSenderOverride;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // The sender service always sets this; the null branch only applies
            // to a direct construction in a test.
            from: $this->fromAddressOverride,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        // Plain text only — no HTML view, no assets, nothing to render.
        return new Content(
            text: 'mail.warmup.message',
            with: ['bodyText' => $this->bodyText],
        );
    }
}
