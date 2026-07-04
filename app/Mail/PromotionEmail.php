<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Promotion (marketing offer) email, fully driven by a site's editable template.
 *
 * Every visible string (subject, hero, body, CTA labels, unsubscribe) arrives
 * pre-rendered in $template — placeholders already substituted and body fields
 * already HTML-safe (see SitePromotionEmail::render()). The "from" address is
 * per-site but constrained to the SendGrid-verified domain. Sent through the
 * shared `sendgrid` mailer.
 */
class PromotionEmail extends Mailable
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
        public readonly string $oneClickUrl = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->template['from_email'], $this->template['from_name']),
            subject: $this->template['subject'],
        );
    }

    /**
     * RFC 8058 one-click unsubscribe headers so Gmail/Yahoo/Apple show a native
     * "Unsubscribe" button that opts the recipient out with a single POST.
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

    public function content(): Content
    {
        return new Content(
            view: 'mail.promotion.offer',
            with: [
                't'              => $this->template,
                'siteName'       => $this->siteName,
                'siteUrl'        => $this->siteUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'buttonColor'    => $this->template['button_color'],
                'accent'         => $this->template['accent_color'],
            ],
        );
    }
}
