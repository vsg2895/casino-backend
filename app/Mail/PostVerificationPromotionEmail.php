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
 * The post-verification promotion email, driven entirely by the global
 * {@see \App\Models\VerificationPromotionEmail} template.
 *
 * A sibling of {@see PromotionEmail} but for the richer, light "thanks for
 * subscribing — here's your welcome gift" design: header brand band, hero,
 * eyebrow label, star-rating highlight box, body copy, CTA, responsible-gambling
 * notice and a dark footer with editable navigation links. Every visible string
 * arrives pre-rendered in $template (placeholders substituted, rich fields
 * HTML-safe — see VerificationPromotionEmail::render()); the palette arrives
 * already defaulted, so the view never guards against a missing colour.
 *
 * $siteName / $siteUrl / $contactEmail are the FIXED Winpalack values from
 * config('promotions.after_verification'), not the subscriber's site — this one
 * stream is deliberately single-brand. See
 * {@see \App\Services\PostVerificationPromotionEmailService}.
 */
class PostVerificationPromotionEmail extends Mailable implements SenderOverridable
{
    use HasSenderOverride;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $template  Rendered template values (incl. footer_links array).
     */
    public function __construct(
        public readonly array $template,
        public readonly string $siteName,
        public readonly string $siteUrl,
        /**
         * Footer contact address, from config('promotions.after_verification').
         * Passed in rather than read in the view so the config key stays the
         * single place these strings live.
         */
        public readonly string $contactEmail,
        public readonly string $unsubscribeUrl,
        public readonly string $oneClickUrl = '',
        public readonly string $greeting = '',
        /**
         * Visibility flag per optional block, from
         * {@see \App\Models\VerificationPromotionEmail::visibleBlocks()}.
         * Defaulted so every existing caller keeps rendering everything.
         *
         * @var array<string, bool>
         */
        public readonly array $visibleBlocks = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddressOverride ?? $this->template['from_email'], $this->template['from_name']),
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
            view: 'mail.promotion.after-verification',
            with: [
                't'              => $this->template,
                'siteName'       => $this->siteName,
                'siteUrl'        => $this->siteUrl,
                'contactEmail'   => $this->contactEmail,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'greeting'       => $this->greeting,
                'visible'        => $this->visibleBlocks,
                // Palette — already defaulted in VerificationPromotionEmail::render().
                'canvas'         => $this->template['background_color'],
                'bodyBg'         => $this->template['body_background_color'],
                'headerColor'    => $this->template['header_color'],
                'headingColor'   => $this->template['heading_color'],
                'textColor'      => $this->template['text_color'],
                'secondaryColor' => $this->template['secondary_text_color'],
                'mutedColor'     => $this->template['muted_text_color'],
                'buttonColor'    => $this->template['button_color'],
                'accent'         => $this->template['accent_color'],
                'footerBg'       => $this->template['footer_background_color'],
                'footerColor'    => $this->template['footer_text_color'],
                'footerLink'     => $this->template['footer_link_color'],
            ],
        );
    }
}
