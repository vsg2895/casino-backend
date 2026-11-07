<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\PromotionEmail;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\SitePromotionEmail;
use App\Models\Unsubscribe;
use App\Support\EmailGreeting;
use Illuminate\Support\Carbon;

/**
 * Builds the promotion offer Mailable from a site's editable template.
 *
 * Centralises placeholder context + rendering so the admin live preview and the
 * "send test" action produce identical output. Mirrors SubscriptionEmailService
 * but for the promotional blast rather than the subscription confirmation.
 */
class PromotionEmailService
{
    /**
     * Placeholder values available to every template string.
     *
     * @return array<string, string>
     */
    public function context(Site $site, string $email, string $unsubscribeUrl): array
    {
        return [
            'site_name'       => $site->name,
            'site_url'        => 'https://' . $site->domain,
            'email'           => $email,
            'year'            => (string) Carbon::now()->year,
            'unsubscribe_url' => $unsubscribeUrl,
        ];
    }

    /** Mailable for a real, persisted subscriber (unsubscribe link is theirs). */
    public function mailForSubscriber(Site $site, SitePromotionEmail $template, Newsletter $newsletter): PromotionEmail
    {
        return $this->mailFor(
            $site,
            $template,
            $newsletter->email,
            $newsletter->unsubscribeTokenFor(Unsubscribe::TYPE_PROMOTION),
            $newsletter->full_name,
        );
    }

    /**
     * Mailable for a (possibly unsaved) template — powers the admin preview and
     * "send test" with a sample subscriber so admins see edits before saving.
     * An optional $sampleName drives the "Dear {name}," greeting in test sends.
     */
    public function previewMail(
        Site $site,
        SitePromotionEmail $template,
        string $sampleEmail = 'subscriber@example.com',
        ?string $sampleName = null,
    ): PromotionEmail {
        // A realistic (random, well-formed) sample token so the preview's
        // unsubscribe link looks like a real one — not a string of zeros.
        return $this->mailFor($site, $template, $sampleEmail, Newsletter::generateUnsubscribeToken(), $sampleName);
    }

    /**
     * Build the Mailable straight from primitives (email + that recipient's
     * promotion unsubscribe token) — no Newsletter model or query required. The
     * caller (e.g. a batch send job) loads the site + template ONCE and reuses
     * them across the whole batch, so nothing here touches the database.
     */
    public function mailFor(
        Site $site,
        SitePromotionEmail $template,
        string $email,
        string $token,
        ?string $fullName = null,
    ): PromotionEmail {
        return $this->mailWithLinks(
            $site,
            $template,
            $email,
            $template->unsubscribeUrl($site, $token),
            Unsubscribe::oneClickUrl($token),
            $fullName,
        );
    }

    /**
     * Build the Mailable with explicit unsubscribe links.
     *
     * For a recipient who is NOT a site subscriber — a Custom Receivers address
     * mailed by a schedule. Their opt-out belongs to their own list
     * (`/api/v1/mailgun-unsubscribe/{token}` or the SendGrid twin), so the
     * visible link and the RFC 8058 one-click target are that list's, not the
     * site's newsletter unsubscribe, which would know nothing about them.
     */
    public function mailWithLinks(
        Site $site,
        SitePromotionEmail $template,
        string $email,
        string $unsubscribeUrl,
        string $oneClickUrl,
        ?string $fullName = null,
    ): PromotionEmail {
        $context = $this->context($site, $email, $unsubscribeUrl);

        return new PromotionEmail(
            template: $template->render($context),
            siteName: $site->name,
            siteUrl: $context['site_url'],
            unsubscribeUrl: $unsubscribeUrl,
            oneClickUrl: $oneClickUrl,
            greeting: EmailGreeting::line($fullName),
            // Which optional blocks this template currently shows. Hiding one is a
            // setting: the block's own text stays in the row untouched.
            visibleBlocks: $template->visibleBlocks(),
        );
    }
}
