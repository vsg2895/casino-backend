<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\NewsletterSubscribedMail;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\SiteEmailTemplate;
use App\Models\Unsubscribe;
use App\Support\EmailGreeting;
use Illuminate\Support\Carbon;

/**
 * Builds the subscription confirmation Mailable from a site's editable template.
 *
 * Centralises placeholder context + rendering so the queued send, the admin
 * live preview and the "send test" action all produce identical output.
 */
class SubscriptionEmailService
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

    /** Mailable for a real, persisted subscriber (used by the queued send). */
    public function mailForSubscriber(Site $site, Newsletter $newsletter): NewsletterSubscribedMail
    {
        $template = $site->emailTemplateOrDefault();

        return $this->build(
            $site,
            $template,
            $newsletter->email,
            $newsletter->unsubscribeTokenFor(Unsubscribe::TYPE_SUBSCRIPTION),
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
        SiteEmailTemplate $template,
        string $sampleEmail = 'subscriber@example.com',
        ?string $sampleName = null,
    ): NewsletterSubscribedMail {
        // A throwaway token keeps the preview unsubscribe link well-formed.
        return $this->build($site, $template, $sampleEmail, str_repeat('0', 64), $sampleName);
    }

    private function build(
        Site $site,
        SiteEmailTemplate $template,
        string $email,
        string $token,
        ?string $fullName = null,
    ): NewsletterSubscribedMail {
        $unsubscribeUrl = $template->unsubscribeUrl($site, $token);
        $context = $this->context($site, $email, $unsubscribeUrl);

        return new NewsletterSubscribedMail(
            template: $template->render($context),
            siteName: $site->name,
            siteUrl: $context['site_url'],
            unsubscribeUrl: $unsubscribeUrl,
            oneClickUrl: Unsubscribe::oneClickUrl($token),
            greeting: EmailGreeting::line($fullName),
        );
    }
}
