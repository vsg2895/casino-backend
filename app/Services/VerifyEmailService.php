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

        // The verify LINK uses the subscription token — VerifyController
        // resolves the subscriber by `unsubscribe_token`.
        $verifyToken = $newsletter->unsubscribeTokenFor(Unsubscribe::TYPE_SUBSCRIPTION);
        // The unsubscribe link uses the verify template's OWN token, so an
        // opt-out from this email is attributed to the "verify" stream.
        $unsubToken = $newsletter->unsubscribeTokenFor(Unsubscribe::TYPE_VERIFY);

        return $this->build(
            $site,
            $template,
            $newsletter->email,
            $template->verifyUrl($site, $verifyToken),
            $template->unsubscribeUrl($site, $unsubToken),
            Unsubscribe::siteOneClickUrl($site, $unsubToken),
            $newsletter->full_name,
        );
    }

    /*
     * There is deliberately no mailForListMember() beside the above.
     *
     * This template asks the recipient to CONFIRM an address they submitted
     * themselves. Every row on a global list was entered by an operator who
     * already has the address, so there is no claim to confirm and nothing for
     * the button to do — which is why the verify template stays newsletters-only
     * ({@see \App\Models\EmailSchedule::SUBSCRIBER_ONLY_TEMPLATES}) and why
     * {@see \App\Models\Contracts\TokenAddressable} carries an opt-out link and
     * no verify link. Compare {@see SubscriptionEmailService}, which DOES have
     * one: a welcome message is about the list that mailed them, so it is
     * meaningful on any audience.
     */

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
        $verifyToken = Newsletter::generateUnsubscribeToken();
        $unsubToken = Newsletter::generateUnsubscribeToken();

        return $this->build(
            $site,
            $template,
            $sampleEmail,
            $template->verifyUrl($site, $verifyToken),
            $template->unsubscribeUrl($site, $unsubToken),
            Unsubscribe::siteOneClickUrl($site, $unsubToken),
            $sampleName,
        );
    }

    /**
     * The one renderer behind all three entry points.
     *
     * It takes the three links already BUILT rather than tokens, because the
     * callers resolve them differently: a subscriber's come from the site's
     * template and carry a separate token per stream, a list member's come from
     * their own list and share one. Passing tokens meant passing the rule for
     * interpreting them as well.
     */
    private function build(
        Site $site,
        SiteVerifyEmail $template,
        string $email,
        string $verifyUrl,
        string $unsubscribeUrl,
        string $oneClickUrl,
        ?string $fullName = null,
    ): VerifyEmailMail {
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
            // Passed in unconditionally, even when the body link is hidden:
            // removing the link is a layout choice and must not change how
            // anyone actually unsubscribes.
            //
            // For a SUBSCRIBER the caller serves it from the site's own domain —
            // the only stream that does. A verification email asks the recipient
            // to trust a link, so advertising a header on an unrelated API host
            // works against it; see {@see Unsubscribe::siteOneClickUrl()}. A
            // list member's comes from their list's own route instead, which is
            // the only place their token resolves.
            oneClickUrl: $oneClickUrl,
            greeting: EmailGreeting::line($fullName),
            // Whether the footer link block is rendered. Coalesced on the model so
            // an unsaved preview template and a legacy row both mean "shown".
            showUnsubscribe: $template->showsUnsubscribeLink(),
            // Which optional footer blocks this template currently shows.
            visibleBlocks: $template->visibleBlocks(),
        );
    }
}
