<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\NewsletterSubscribedMail;
use App\Models\Contracts\TokenAddressable;
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
        $token = $newsletter->unsubscribeTokenFor(Unsubscribe::TYPE_SUBSCRIPTION);

        return $this->build(
            $site,
            $template,
            $newsletter->email,
            $template->unsubscribeUrl($site, $token),
            Unsubscribe::oneClickUrl($token),
            $newsletter->full_name,
        );
    }

    /**
     * Mailable for an address on a GLOBAL list — Mailgun or SendGrid receivers,
     * or warmup — which a schedule can now target with this template.
     *
     * The message is the site's own subscription template, rendered exactly as
     * a subscriber's is. The ONE difference is the opt-out: it points at the
     * list the address is actually on, through that row's own token, so the
     * click lands where the list will honour it. Pointing a receiver at the
     * site's newsletter unsubscribe would record an opt-out against a
     * subscription they never had and leave them on the list that mailed them.
     *
     * This is the method that made the template available off the newsletters
     * audience: nothing here needs a subscriber record, only an address, a name
     * and a working opt-out.
     */
    public function mailForListMember(Site $site, TokenAddressable $member): NewsletterSubscribedMail
    {
        return $this->build(
            $site,
            $site->emailTemplateOrDefault(),
            (string) $member->email,
            $member->unsubscribeUrl(),
            $member->oneClickUrl(),
            $member->recipientName(),
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
        // A realistic (random, well-formed) sample token so the preview's
        // unsubscribe link looks like a real one — not a string of zeros.
        $token = Newsletter::generateUnsubscribeToken();

        return $this->build(
            $site,
            $template,
            $sampleEmail,
            $template->unsubscribeUrl($site, $token),
            Unsubscribe::oneClickUrl($token),
            $sampleName,
        );
    }

    /**
     * The one renderer behind all three entry points.
     *
     * It takes the opt-out links already BUILT rather than a token, because the
     * three callers resolve them differently — a subscriber's from the site's
     * template, a list member's from their own list — and the alternative was a
     * token plus a flag saying how to interpret it.
     */
    private function build(
        Site $site,
        SiteEmailTemplate $template,
        string $email,
        string $unsubscribeUrl,
        string $oneClickUrl,
        ?string $fullName = null,
    ): NewsletterSubscribedMail {
        $context = $this->context($site, $email, $unsubscribeUrl);

        return new NewsletterSubscribedMail(
            template: $template->render($context),
            siteName: $site->name,
            siteUrl: $context['site_url'],
            unsubscribeUrl: $unsubscribeUrl,
            oneClickUrl: $oneClickUrl,
            greeting: EmailGreeting::line($fullName),
        );
    }
}
