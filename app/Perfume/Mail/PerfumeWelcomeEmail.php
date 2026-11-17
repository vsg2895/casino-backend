<?php

declare(strict_types=1);

namespace App\Perfume\Mail;

use App\Perfume\Models\Setting;
use App\Perfume\Models\Subscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The one email the shop sends: the confirmation a visitor gets after using
 * the subscribe box on the storefront.
 *
 * There is no template library behind it. The words are in the Blade and the
 * shop's own details — name, address, phone, email — come from Settings, so
 * the only thing an operator changes is what is already on the Settings
 * screen.
 *
 * The From is the shop's address when Settings has one, else the application
 * default. With SendGrid that address must sit on a domain SendGrid has been
 * told to authenticate, or the message is accepted and then silently dropped.
 */
class PerfumeWelcomeEmail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Subscriber $subscriber) {}

    public function envelope(): Envelope
    {
        $settings = Setting::current();
        $address = trim((string) $settings->email);

        return new Envelope(
            from: $address !== '' ? new Address($address, (string) $settings->site_name) : null,
            subject: 'Welcome to ' . $settings->site_name,
        );
    }

    /** RFC 8058 one-click unsubscribe, so Gmail and Apple show their native button. */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe'      => '<' . $this->unsubscribeUrl() . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        $settings = Setting::current();

        return new Content(
            view: 'mail.perfume.welcome',
            with: [
                'name'        => trim((string) $this->subscriber->name) !== '' ? $this->subscriber->name : null,
                'siteName'    => $settings->site_name,
                'siteUrl'     => rtrim((string) config('perfume.site_url'), '/'),
                'accent'      => $settings->accent_color ?: '#D6F25C',
                'phone'       => $settings->phone,
                'email'       => $settings->email,
                'unsubscribe' => $this->unsubscribeUrl(),
            ],
        );
    }

    private function unsubscribeUrl(): string
    {
        return rtrim((string) config('perfume.site_url'), '/') . '/unsubscribe/' . $this->subscriber->token;
    }
}
