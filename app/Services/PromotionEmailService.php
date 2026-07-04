<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\PromotionEmail;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\SitePromotionEmail;
use App\Models\Unsubscribe;
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
        return $this->build(
            $site,
            $template,
            $newsletter->email,
            $newsletter->unsubscribeTokenFor(Unsubscribe::TYPE_PROMOTION),
        );
    }

    /**
     * Mailable for a (possibly unsaved) template — powers the admin preview and
     * "send test" with a sample subscriber so admins see edits before saving.
     */
    public function previewMail(
        Site $site,
        SitePromotionEmail $template,
        string $sampleEmail = 'subscriber@example.com',
    ): PromotionEmail {
        // A throwaway token keeps the preview unsubscribe link well-formed.
        return $this->build($site, $template, $sampleEmail, str_repeat('0', 64));
    }

    private function build(
        Site $site,
        SitePromotionEmail $template,
        string $email,
        string $token,
    ): PromotionEmail {
        $unsubscribeUrl = $template->unsubscribeUrl($site, $token);
        $context = $this->context($site, $email, $unsubscribeUrl);

        return new PromotionEmail(
            template: $template->render($context),
            siteName: $site->name,
            siteUrl: $context['site_url'],
            unsubscribeUrl: $unsubscribeUrl,
            oneClickUrl: Unsubscribe::oneClickUrl($token),
        );
    }
}
