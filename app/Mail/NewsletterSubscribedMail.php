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
 * Subscription confirmation email, fully driven by a site's editable template.
 *
 * Every visible string (subject, header band, body, footer, unsubscribe label)
 * arrives pre-rendered in $template — placeholders already substituted and body
 * fields already HTML-safe (see SiteEmailTemplate::render()). The "from" address
 * is per-site but constrained to the SendGrid-verified domain. Dispatched from
 * SendNewsletterWelcomeEmail and sent through the shared `sendgrid` mailer.
 */
class NewsletterSubscribedMail extends Mailable
{
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
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->template['from_email'], $this->template['from_name']),
            subject: $this->template['subject'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.newsletter.subscribed',
            with: [
                't'              => $this->template,
                'siteName'       => $this->siteName,
                'siteUrl'        => $this->siteUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'accent'         => $this->template['accent_color'],
            ],
        );
    }
}
