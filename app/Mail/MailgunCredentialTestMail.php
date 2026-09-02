<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Connection test for a stored Mailgun credential.
 *
 * Deliberately NOT registered in {@see \App\Services\Mail\EmailTemplateCatalog}.
 * That catalog is shared with the SendGrid test dialog and the warmup template
 * picker, and this belongs to neither — it is specific to the Mailgun
 * credentials screen, so it is built directly by
 * {@see \App\Http\Controllers\Api\Admin\MailgunKeyController::test()} and the
 * other sections are left exactly as they were.
 *
 * The catalog's other entries render a SITE's real template, which answers
 * "does this credential deliver my actual mail". This one answers the narrower
 * question worth asking first: does the credential authenticate and hand a
 * message off at all. It therefore takes no Site and reads no site template, so
 * a failure can only be the credential, its domain, its region or its sender.
 *
 * The content is purely diagnostic. It is addressed to nobody in particular,
 * asserts nothing about the recipient, and carries no tracking, no unsubscribe
 * footer and no marketing shape — it goes to an address the admin typed seconds
 * earlier and must never be mistakable for real mail from anyone.
 */
class MailgunCredentialTestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        /** The credential's display name, so several tests can be told apart. */
        public readonly string $credentialName,
        /** The sending domain the credential is bound to. */
        public readonly string $domain,
        /** "us" or "eu" — the API host the credential authenticates against. */
        public readonly string $region,
        /**
         * The credential's own from_address, or null.
         *
         * There is no site template behind this message, so unlike a real send
         * there is nothing to inherit a sender from. Null simply leaves Laravel's
         * configured default in place.
         */
        public readonly ?string $fromAddress = null,
        /** The credential's from_name, used only alongside $fromAddress. */
        public readonly ?string $fromName = null,
    ) {}

    public function envelope(): Envelope
    {
        $from = $this->fromAddress !== null && $this->fromAddress !== ''
            ? new Address($this->fromAddress, $this->fromName ?: null)
            : null;

        return new Envelope(
            from: $from,
            subject: sprintf('Mailgun connection test — %s', $this->credentialName),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.mailgun-credential-test',
            with: [
                'credentialName' => $this->credentialName,
                'domain'         => $this->domain,
                'region'         => strtoupper($this->region),
                'sentAt'         => now()->utc()->format('j F Y, H:i') . ' UTC',
            ],
        );
    }
}
