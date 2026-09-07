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
 * A test must be INDISTINGUISHABLE from what the campaign will actually
 * deliver — same subject, same body, same Blade wrapper, same palette — because
 * an admin approves the copy from what lands in their inbox. Anything the test
 * substitutes for the real message is a chance to sign off on something the
 * receivers never get.
 *
 * That is what this class previously got wrong. It fell back to a plain
 * "connection test" paragraph whenever `message_html` was empty, and blanked
 * `{{email}}` instead of substituting it, so the delivered test could look
 * nothing like the campaign the preview pane was showing.
 *
 * The body is resolved in this order:
 *
 *   1. the credential's own stored `message_html` — a real campaign send;
 *   2. the seeded promotion template, which is EXACTLY what the preview pane
 *      renders for a credential nobody has configured yet;
 *   3. a short connection-test paragraph, reachable only when no site is
 *      registered and therefore no promotion copy exists anywhere to send.
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
        /**
         * The address this test is going to. Substituted for `{{email}}` so the
         * placeholder resolves the way it will in a real send, instead of
         * collapsing to nothing and hiding a broken line of copy.
         */
        public readonly ?string $recipientEmail = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) $this->credential->from_address,
                (string) $this->credential->from_name ?: null,
            ),
            // Same chain as the body: the credential's own subject, then the
            // seeded one, and only then something that names the test. A real
            // send with no subject uses 'Message', so this stops short of
            // inventing a line the campaign would never carry.
            subject: $this->resolveSubject(),
        );
    }

    public function content(): Content
    {
        [$template, $body] = $this->resolveMessage();

        $body = str_replace(
            ['{{name}}', '{{email}}'],
            [
                e((string) ($this->recipientName ?? 'there')),
                e((string) ($this->recipientEmail ?? '')),
            ],
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

    /**
     * The palette and the body, resolved together.
     *
     * They travel as a pair on purpose: falling back to the seeded copy while
     * keeping the credential's own (blank) palette would render the seed's
     * layout in the wrong colours, which is a preview of nothing.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function resolveMessage(): array
    {
        $stored = trim($this->credential->campaignHtml());

        if ($stored !== '') {
            return [MailgunReceiverTemplate::merged($this->credential->campaignTemplate()), $stored];
        }

        $seed = MailgunReceiverTemplate::seed();

        if (! MailgunReceiverTemplate::isEmpty($seed['template'])) {
            $template = MailgunReceiverTemplate::merged($seed['template']);

            return [$template, MailgunReceiverTemplate::render($template)];
        }

        // Nothing authored anywhere — no stored message and no site to seed
        // from. The connection is still the one thing worth answering.
        return [
            MailgunReceiverTemplate::merged(null),
            '<p>This is a connection test from <strong>'
                . e($this->credential->name)
                . '</strong>. If you are reading it, the SMTP settings authenticate and deliver.</p>',
        ];
    }

    /**
     * Named `resolveSubject` and not `subject`: Mailable already declares a
     * public `subject()`, and redeclaring it private is a fatal error.
     */
    private function resolveSubject(): string
    {
        $own = trim($this->credential->campaignSubject());

        if ($own !== '') {
            return $own;
        }

        $seeded = trim((string) (MailgunReceiverTemplate::seed()['subject'] ?? ''));

        return $seeded !== '' ? $seeded : 'Test message from ' . $this->credential->name;
    }
}
