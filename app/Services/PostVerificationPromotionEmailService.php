<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\PostVerificationPromotionEmail;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\Unsubscribe;
use App\Models\VerificationPromotionEmail;
use App\Support\EmailGreeting;
use Illuminate\Support\Carbon;

/**
 * Builds the post-verification promotion Mailable from the global template.
 *
 * The sibling of {@see PromotionEmailService} but for the richer, light
 * after-verification design ({@see PostVerificationPromotionEmail}). Centralises
 * placeholder context + rendering so the admin live preview, the "send test"
 * action and the real automatic send all produce identical output.
 *
 * BRANDING IS FIXED, NOT PER-SITE. Unlike every other stream, this email always
 * goes out as Winpalack: {{site_name}}, {{site_url}} and {{site_domain}} come
 * from config('promotions.after_verification') and never from the subscriber's
 * Site row. One template carrying one brand's offer must not introduce itself
 * under six different names — a subscriber who confirmed on roulettingo.com used
 * to receive a ROULETTINGO header above a Winpalack offer.
 *
 * The Site is still required, for ONE thing: the unsubscribe URL. See mailFor().
 */
class PostVerificationPromotionEmailService
{
    /**
     * Placeholder values available to every template string.
     *
     * FIXED (config): site_name, site_url, site_domain, contact_email — the same
     * values for every recipient, whatever site they came from.
     *
     * PER-RECIPIENT: email and unsubscribe_url. Neither may ever be moved into
     * config — a shared unsubscribe link would opt the wrong person out, and a
     * shared address would be a different subscriber's.
     *
     * year is current-year and site-independent, so it stays dynamic.
     *
     * @return array<string, string>
     */
    public function context(string $email, string $unsubscribeUrl): array
    {
        $brand = self::brand();

        return [
            'site_name'       => $brand['site_name'],
            'site_url'        => $brand['site_url'],
            // Bare domain (no scheme) for footer copy — "confirmed at
            // winpalack.com" — where "https://" would read wrong.
            'site_domain'     => $brand['site_domain'],
            // Footer contact line. A placeholder rather than a literal in the
            // template row, so the config key stays the single source.
            'contact_email'   => $brand['contact_email'],
            'email'           => $email,
            'year'            => (string) Carbon::now()->year,
            'unsubscribe_url' => $unsubscribeUrl,
        ];
    }

    /**
     * The fixed branding this template always carries.
     *
     * @return array{site_name: string, site_url: string, site_domain: string, contact_email: string}
     */
    public static function brand(): array
    {
        /** @var array{site_name: string, site_url: string, site_domain: string, contact_email: string} $brand */
        $brand = config('promotions.after_verification');

        return $brand;
    }

    /**
     * Mailable for a (possibly unsaved) template — powers the admin preview and
     * "send test" with a sample subscriber so admins see edits before saving.
     * An optional $sampleName drives the "Dear {name}," greeting in test sends.
     *
     * $site is the sample subscriber's site and affects ONLY the unsubscribe
     * link, exactly as it does in a real send — which is what makes the test
     * email byte-identical in branding to the automatic one.
     */
    public function previewMail(
        Site $site,
        VerificationPromotionEmail $template,
        string $sampleEmail = 'subscriber@example.com',
        ?string $sampleName = null,
    ): PostVerificationPromotionEmail {
        // A realistic (random, well-formed) sample token so the preview's
        // unsubscribe link looks like a real one — not a string of zeros.
        return $this->mailFor($site, $template, $sampleEmail, Newsletter::generateUnsubscribeToken(), $sampleName);
    }

    /**
     * Build the Mailable straight from primitives (email + that recipient's
     * post-verification-promotion unsubscribe token) — no Newsletter model or
     * query required, so a batch send can load the site + template once and reuse
     * them across every recipient.
     *
     * $site is used for the UNSUBSCRIBE URL AND NOTHING ELSE. The opt-out landing
     * page is a route on each site's own domain, and links already delivered
     * point there, so it must keep resolving against the subscriber's own site.
     * Every visible brand string comes from config instead — see the class
     * docblock.
     */
    public function mailFor(
        Site $site,
        VerificationPromotionEmail $template,
        string $email,
        string $token,
        ?string $fullName = null,
    ): PostVerificationPromotionEmail {
        $unsubscribeUrl = $template->unsubscribeUrl($site, $token);
        $context = $this->context($email, $unsubscribeUrl);

        return new PostVerificationPromotionEmail(
            template: $template->render($context),
            // From config, NOT $site — the layout's header, image alt text and
            // footer address line all read these.
            siteName: $context['site_name'],
            siteUrl: $context['site_url'],
            contactEmail: $context['contact_email'],
            unsubscribeUrl: $unsubscribeUrl,
            oneClickUrl: Unsubscribe::oneClickUrl($token),
            greeting: EmailGreeting::line($fullName),
            // Which optional blocks this template currently shows. Hiding one is a
            // setting: the block's text stays in the row untouched.
            visibleBlocks: $template->visibleBlocks(),
        );
    }
}
