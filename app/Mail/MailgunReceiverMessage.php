<?php

declare(strict_types=1);

namespace App\Mail;

use App\Contracts\ReceiverCampaignCredential;
use App\Models\CampaignReceiver;
use App\Support\Mail\MailgunReceiverTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * One message to one receiver on a Custom Receivers list.
 *
 * The credential is whatever is sending — a Mailgun API key, a stored SMTP
 * server or a SendGrid key. All of them implement {@see ReceiverCampaignCredential}, so this mailable
 * never learns which, and the channels cannot drift into composing
 * different messages from the same authored template.
 *
 * The body is the credential's own `message_html`, authored in the admin. What
 * is NOT authored there — and cannot be removed by editing it — is the
 * unsubscribe apparatus: {@see content()} appends a visible link and
 * {@see headers()} sets RFC 8058 List-Unsubscribe / List-Unsubscribe-Post.
 *
 * That split is deliberate. A bulk send whose opt-out depends on whoever wrote
 * the template remembering to include one will eventually go out without it, and
 * a 100k send with no unsubscribe is how a sending domain gets blocked. Putting
 * it in the mailable makes it structural rather than editorial.
 *
 * Personalisation is limited to {{name}} and {{email}}, substituted from the
 * receiver's own row. There is no expression evaluation and no Blade compilation
 * of admin input — the stored HTML is escaped-by-construction at the point it is
 * written, and rendered here as trusted-but-inert content.
 */
class MailgunReceiverMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ReceiverCampaignCredential $credential,
        public readonly CampaignReceiver $receiver,
    ) {}

    public function envelope(): Envelope
    {
        $from = null;

        // Empty means "let the transport decide". For Mailgun that is the site
        // template's sender, which is the behaviour that predates this
        // interface; a stored SMTP server always supplies one, because it has no
        // other source of identity.
        if ((string) $this->credential->campaignFromAddress() !== '') {
            $from = new Address(
                (string) $this->credential->campaignFromAddress(),
                (string) $this->credential->campaignFromName() ?: null,
            );
        }

        return new Envelope(
            from: $from,
            subject: $this->credential->campaignSubject() ?: 'Message',
        );
    }

    public function content(): Content
    {
        // The palette the body was rendered with, so the appended unsubscribe
        // block sits on the same column rather than on a white strip below it.
        // Read from the template fields, never from the rendered HTML.
        $palette = MailgunReceiverTemplate::merged($this->credential->campaignTemplate());

        return new Content(
            view: 'mail.mailgun-receiver-message',
            with: [
                'bodyHtml'        => $this->personalise($this->credential->campaignHtml()),
                'unsubscribeUrl'  => $this->unsubscribeUrl(),
                'backgroundColor' => $palette['background_color'],
                'mutedColor'      => $palette['muted_text_color'],
                'accentColor'     => $palette['accent_color'],
                // Wording only — the link itself is appended by the layout and
                // cannot be removed by editing the template.
                'unsubscribeLabel' => $palette['unsubscribe_label'],
            ],
        );
    }

    /**
     * RFC 8058 one-click headers.
     *
     * Both a mailto: and an https: value, as the brief requires: Gmail and Apple
     * Mail render a native unsubscribe button from these, which measurably
     * reduces the spam complaints that would otherwise land on the domain.
     */
    public function headers(): Headers
    {
        $url = $this->unsubscribeUrl();
        $mailto = (string) config('mail.from.address');

        $values = ['<' . $url . '>'];

        if ($mailto !== '') {
            $values[] = '<mailto:' . $mailto . '?subject=unsubscribe>';
        }

        return new Headers(text: [
            'List-Unsubscribe'      => implode(', ', $values),
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    /** The receiver's own list's link — see CampaignReceiver::unsubscribeUrl(). */
    private function unsubscribeUrl(): string
    {
        return $this->receiver->unsubscribeUrl();
    }

    /**
     * Substitute the two supported placeholders.
     *
     * Values are escaped before insertion: the receiver's name arrives from a
     * spreadsheet an admin uploaded, so treating it as trusted HTML would make
     * an imported file an injection vector into every message it addresses.
     */
    private function personalise(string $html): string
    {
        return str_replace(
            ['{{name}}', '{{email}}'],
            [
                e((string) ($this->receiver->name ?? '')),
                e((string) $this->receiver->email),
            ],
            $html,
        );
    }
}
