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
 */
class PostVerificationPromotionEmailService
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
            // Bare domain (no scheme) for footer copy — "confirmed at winpalack.com",
            // "info@winpalack.com" — where "https://" would read wrong.
            'site_domain'     => $site->domain,
            'email'           => $email,
            'year'            => (string) Carbon::now()->year,
            'unsubscribe_url' => $unsubscribeUrl,
        ];
    }

    /**
     * Mailable for a (possibly unsaved) template — powers the admin preview and
     * "send test" with a sample subscriber so admins see edits before saving.
     * An optional $sampleName drives the "Dear {name}," greeting in test sends.
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
     */
    public function mailFor(
        Site $site,
        VerificationPromotionEmail $template,
        string $email,
        string $token,
        ?string $fullName = null,
    ): PostVerificationPromotionEmail {
        $unsubscribeUrl = $template->unsubscribeUrl($site, $token);
        $context = $this->context($site, $email, $unsubscribeUrl);

        return new PostVerificationPromotionEmail(
            template: $template->render($context),
            siteName: $site->name,
            siteUrl: $context['site_url'],
            unsubscribeUrl: $unsubscribeUrl,
            oneClickUrl: Unsubscribe::oneClickUrl($token),
            greeting: EmailGreeting::line($fullName),
            // Which optional blocks this template currently shows. Hiding one is a
            // setting: the block's text stays in the row untouched.
            visibleBlocks: $template->visibleBlocks(),
        );
    }
}
