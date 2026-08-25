<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\VerifyEmailMail;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\SiteVerifyEmail;
use App\Models\Unsubscribe;
use App\Support\EmailGreeting;
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
    public function context(Site $site, string $email, string $unsubscribeUrl, string $verifyUrl): array
    {
        return [
            'site_name'       => $site->name,
            'site_url'        => 'https://' . $site->domain,
            'email'           => $email,
            'year'            => (string) Carbon::now()->year,
            'unsubscribe_url' => $unsubscribeUrl,
            'verify_url'      => $verifyUrl,
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
            // The verify LINK uses the subscription token — VerifyController
            // resolves the subscriber by `unsubscribe_token`.
            $newsletter->unsubscribeTokenFor(Unsubscribe::TYPE_SUBSCRIPTION),
            $newsletter->full_name,
            // The unsubscribe link uses the verify template's OWN token, so an
            // opt-out from this email is attributed to the "verify" stream.
            $newsletter->unsubscribeTokenFor(Unsubscribe::TYPE_VERIFY),
        );
    }

    /**
     * Mailable for a (possibly unsaved) template — powers the admin preview and
     * "send test" with a sample subscriber so admins see edits before saving.
     * An optional $sampleName drives the "Dear {name}," greeting in test sends.
     */
    public function previewMail(
        Site $site,
        SiteVerifyEmail $template,
        string $sampleEmail = 'subscriber@example.com',
        ?string $sampleName = null,
    ): VerifyEmailMail {
        // Realistic (random, well-formed) sample tokens so the preview's verify
        // and unsubscribe links look like real ones — not a string of zeros. The
        // two links carry independent tokens in a real send, so use two here too.
        return $this->build(
            $site,
            $template,
            $sampleEmail,
            Newsletter::generateUnsubscribeToken(),
            $sampleName,
            Newsletter::generateUnsubscribeToken(),
        );
    }

    /**
     * @param  string       $token             The subscription token — credential for the verify LINK.
     * @param  string|null  $unsubscribeToken  The verify template's own token for the unsubscribe LINK;
     *                                          falls back to $token when absent (preview convenience).
     */
    private function build(
        Site $site,
        SiteVerifyEmail $template,
        string $email,
        string $token,
        ?string $fullName = null,
        ?string $unsubscribeToken = null,
    ): VerifyEmailMail {
        $unsubToken = $unsubscribeToken ?? $token;
        $unsubscribeUrl = $template->unsubscribeUrl($site, $unsubToken);
        $verifyUrl = $template->verifyUrl($site, $token);
        $context = $this->context($site, $email, $unsubscribeUrl, $verifyUrl);

        return new VerifyEmailMail(
            template: $template->render($context),
            siteName: $site->name,
            siteUrl: $context['site_url'],
            unsubscribeUrl: $unsubscribeUrl,
            verifyUrl: $verifyUrl,
            // One-click uses the unsubscribe token too, so a native "Unsubscribe"
            // is attributed to the verify stream just like the in-body link.
            //
            // Built unconditionally, even when the body link is hidden: removing
            // the link is a layout choice and must not change how anyone actually
            // unsubscribes.
            //
            // Served from the SITE's domain — the only stream that does. A
            // verification email asks the recipient to trust a link, so advertising
            // a header on an unrelated API host works against it. The endpoint
            // behind it is the same one every other stream uses; see
            // {@see Unsubscribe::siteOneClickUrl()}.
            oneClickUrl: Unsubscribe::siteOneClickUrl($site, $unsubToken),
            greeting: EmailGreeting::line($fullName),
            // Whether the footer link block is rendered. Coalesced on the model so
            // an unsaved preview template and a legacy row both mean "shown".
            showUnsubscribe: $template->showsUnsubscribeLink(),
        );
    }
}
