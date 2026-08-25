<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasSenderOverride;
use App\Mail\Contracts\SenderOverridable;
use App\Services\Mail\Transport\SendgridClickTrackingClient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * "Verify your email" message, fully driven by a site's editable template.
 *
 * Mirrors {@see NewsletterSubscribedMail}: every visible string arrives
 * pre-rendered in $template. Used by the admin preview + "send test" over SMTP;
 * a `fromAddressOverride` supports the SendGrid-verified sender for real sends.
 */
class VerifyEmailMail extends Mailable implements SenderOverridable
{
    use HasSenderOverride;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, string>  $template  Rendered template strings.
     */
    /**
     * @param  bool  $showUnsubscribe  Whether the footer unsubscribe LINK is
     *                                 rendered. Defaults to true so every existing
     *                                 caller keeps its current output. It gates the
     *                                 body block only — {@see headers()} still emits
     *                                 List-Unsubscribe either way.
     */
    public function __construct(
        public readonly array $template,
        public readonly string $siteName,
        public readonly string $siteUrl,
        public readonly string $unsubscribeUrl,
        public readonly string $verifyUrl = '',
        public readonly string $oneClickUrl = '',
        public readonly string $greeting = '',
        public readonly bool $showUnsubscribe = true,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddressOverride ?? $this->template['from_email'], $this->template['from_name']),
            subject: $this->template['subject'],
        );
    }

    /**
     * The List-Unsubscribe pair is DELIBERATELY independent of $showUnsubscribe.
     *
     * Hiding the body link is a layout choice; removing the RFC 8058 headers
     * would change how recipients opt out and how mailbox providers score the
     * message. The one-click header stays either way.
     *
     * The click-tracking marker is set here, and ONLY here, so it applies to the
     * verification email alone. SendGrid otherwise rewrites every link to
     * sendgrid.net — and this message shows its confirmation URL twice, as a
     * button and as pasted text. A recipient asked to confirm their address by
     * clicking an unfamiliar domain reads as phishing, which is exactly the
     * judgement that lands the message in spam.
     *
     * The marker never leaves the app: {@see SendgridClickTrackingClient} strips
     * it and swaps in `tracking_settings.click_tracking`. Setting it in the
     * message rather than the payload keeps this independent of the transport —
     * over SMTP (the admin "send test") it is an inert X- header.
     */
    public function headers(): Headers
    {
        $text = [SendgridClickTrackingClient::DISABLE_HEADER => 'disabled'];

        if ($this->oneClickUrl !== '') {
            // Angle brackets are mandatory: RFC 2369 defines the value as a URL
            // in <>, and providers reject the header without them.
            $text['List-Unsubscribe'] = '<' . $this->oneClickUrl . '>';
            $text['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        return new Headers(text: $text);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.verify.email',
            with: [
                't'              => $this->template,
                'siteName'       => $this->siteName,
                'siteUrl'        => $this->siteUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'verifyUrl'      => $this->verifyUrl,
                'greeting'       => $this->greeting,
                'showUnsubscribe' => $this->showUnsubscribe,
                'accent'         => $this->template['accent_color'],
            ],
        );
    }
}
