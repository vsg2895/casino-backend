<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasSenderOverride;
use App\Mail\Contracts\SenderOverridable;
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
    public function __construct(
        public readonly array $template,
        public readonly string $siteName,
        public readonly string $siteUrl,
        public readonly string $unsubscribeUrl,
        public readonly string $verifyUrl = '',
        public readonly string $oneClickUrl = '',
        public readonly string $greeting = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddressOverride ?? $this->template['from_email'], $this->template['from_name']),
            subject: $this->template['subject'],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: $this->oneClickUrl === '' ? [] : [
                'List-Unsubscribe'      => '<' . $this->oneClickUrl . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        );
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
                'accent'         => $this->template['accent_color'],
            ],
        );
    }
}
