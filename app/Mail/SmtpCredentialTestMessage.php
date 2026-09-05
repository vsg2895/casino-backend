<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\SmtpCredential;
use App\Support\Mail\MailgunReceiverTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One test message through one stored SMTP credential.
 *
 * Renders the credential's OWN configured message, through the same Blade
 * wrapper a real send uses, so the test answers both questions an admin
 * actually has: does this server authenticate, and does my message look right.
 * A credential with no message configured falls back to a plain confirmation
 * body, so "does it connect" is still answerable before any copy is written.
 *
 * The unsubscribe link points at '#'. A test has no receiver and therefore no
 * token, and minting one for a throwaway send would put a working opt-out URL
 * for a real person into a test message.
 */
class SmtpCredentialTestMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly SmtpCredential $credential,
        public readonly ?string $recipientName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) $this->credential->from_address,
                (string) $this->credential->from_name ?: null,
            ),
            subject: $this->credential->campaignSubject()
                ?: 'Test message from ' . $this->credential->name,
        );
    }

    public function content(): Content
    {
        $template = MailgunReceiverTemplate::merged($this->credential->campaignTemplate());

        $body = $this->credential->campaignHtml();

        if ($body === '') {
            $body = '<p>This is a connection test from <strong>'
                . e($this->credential->name)
                . '</strong>. If you are reading it, the SMTP settings authenticate and deliver.</p>';
        }

        // {{name}} is the only substitution a test can honestly make — there is
        // no receiver row, so {{email}} resolves to the address it was sent to.
        $body = str_replace(
            ['{{name}}', '{{email}}'],
            [e((string) ($this->recipientName ?? 'there')), ''],
            $body,
        );

        return new Content(
            view: 'mail.mailgun-receiver-message',
            with: [
                'bodyHtml'        => $body,
                'unsubscribeUrl'  => '#',
                'backgroundColor' => $template['background_color'],
                'mutedColor'      => $template['muted_text_color'],
                'accentColor'     => $template['accent_color'],
            ],
        );
    }
}
