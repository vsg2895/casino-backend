<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\VerifyEmailMail;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\SiteVerifyEmail;
use App\Models\Unsubscribe;
use Illuminate\Support\Carbon;

/**
 * Builds the "verify your email" Mailable from a site's editable template.
 *
 * Mirrors {@see SubscriptionEmailService} so the admin live preview and the
 * "send test" action produce identical output.
 */
class VerifyEmailService
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

    /** Mailable for a real, persisted subscriber (used by "send test"). */
    public function mailForSubscriber(Site $site, Newsletter $newsletter): VerifyEmailMail
    {
        $template = $site->verifyEmailOrDefault();

        return $this->build(
            $site,
            $template,
            $newsletter->email,
            $newsletter->unsubscribeTokenFor(Unsubscribe::TYPE_SUBSCRIPTION),
        );
    }

    /**
     * Mailable for a (possibly unsaved) template — powers the admin preview and
     * "send test" with a sample subscriber so admins see edits before saving.
     */
    public function previewMail(
        Site $site,
        SiteVerifyEmail $template,
        string $sampleEmail = 'subscriber@example.com',
    ): VerifyEmailMail {
        return $this->build($site, $template, $sampleEmail, str_repeat('0', 64));
    }

    private function build(
        Site $site,
        SiteVerifyEmail $template,
        string $email,
        string $token,
    ): VerifyEmailMail {
        $unsubscribeUrl = $template->unsubscribeUrl($site, $token);
        $context = $this->context($site, $email, $unsubscribeUrl);

        return new VerifyEmailMail(
            template: $template->render($context),
            siteName: $site->name,
            siteUrl: $context['site_url'],
            unsubscribeUrl: $unsubscribeUrl,
            oneClickUrl: Unsubscribe::oneClickUrl($token),
        );
    }
}
