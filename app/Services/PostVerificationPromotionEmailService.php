<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\PostVerificationPromotionEmail;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\SocialLink;
use App\Models\Unsubscribe;
use App\Models\VerificationPromotionEmail;
use App\Models\VerificationPromotionOverride;
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
    /** Display names where ucfirst() of the platform key would read wrong. */
    private const array PLATFORM_NAMES = [
        'twitter'  => 'X',
        'youtube'  => 'YouTube',
        'tiktok'   => 'TikTok',
        'linkedin' => 'LinkedIn',
    ];

    /** Where the Follow row's platform icons live, under public/. */
    private const string ICON_DIR = 'images/email/social';

    /**
     * Memo for socialLinks(): a batch send builds one Mailable per recipient,
     * and the brand's links are the same for every one of them.
     *
     * @var list<array{platform: string, label: string, url: string, icon_url: string|null}>|null
     */
    private ?array $socialLinks = null;

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
            'site_name' => $brand['site_name'],
            'site_url' => $brand['site_url'],
            // Bare domain (no scheme) for footer copy — "confirmed at
            // winpalack.com" — where "https://" would read wrong.
            'site_domain' => $brand['site_domain'],
            // Footer contact line. A placeholder rather than a literal in the
            // template row, so the config key stays the single source.
            'contact_email' => $brand['contact_email'],
            'email' => $email,
            'year' => (string) Carbon::now()->year,
            'unsubscribe_url' => $unsubscribeUrl,
        ];
    }

    /**
     * The fixed branding this template always carries.
     *
     * @return array{site_name: string, site_url: string, site_domain: string, contact_email: string, social_site_slug: string}
     */
    public static function brand(): array
    {
        /** @var array{site_name: string, site_url: string, site_domain: string, contact_email: string, social_site_slug: string} $brand */
        $brand = config('promotions.after_verification');

        return $brand;
    }

    /**
     * The brand's social accounts for the footer's Follow row — the ACTIVE
     * links of the site named by config('promotions.after_verification.
     * social_site_slug'), in the order the admin's Social Links page sets.
     *
     * Read from that one site, never the subscriber's: like every other brand
     * string in this email, the accounts are Winpalack's whichever site the
     * subscriber confirmed on. Managed in the admin under Social Links, so
     * adding, reordering or switching off an account changes the next email
     * with no template edit.
     *
     * Only http(s) URLs are kept — a row holding anything else would put an
     * unclickable or unsafe href in a mail client. A missing site or no active
     * links gives [], and the Follow row is then left out entirely.
     *
     * Each link carries its icon: a PNG in public/images/email/social/, one per
     * SocialLink::PLATFORMS entry (the sites' footer glyphs on a white disc,
     * 96px for a 32px slot). PNG because Gmail and Outlook strip SVG; an
     * absolute URL off APP_URL because a mail client fetches it from outside.
     * A platform with no file gets null and the email falls back to its name.
     *
     * @return list<array{platform: string, label: string, url: string, icon_url: string|null}>
     */
    public function socialLinks(): array
    {
        if ($this->socialLinks !== null) {
            return $this->socialLinks;
        }

        $site = Site::query()->where('slug', self::brand()['social_site_slug'])->first();

        if ($site === null) {
            return $this->socialLinks = [];
        }

        return $this->socialLinks = SocialLink::query()
            ->where('site_id', $site->id)
            ->active()
            ->ordered()
            ->get()
            ->filter(static fn (SocialLink $link): bool => preg_match('#^https?://#i', (string) $link->url) === 1)
            ->map(static fn (SocialLink $link): array => [
                'platform' => $link->platform,
                // The PLATFORM's name, not the row's label: the label is an
                // optional handle ("@winpalack"), the same on every account, so
                // three buttons reading "@winpalack" would not say which is which.
                'label'    => self::PLATFORM_NAMES[$link->platform] ?? ucfirst($link->platform),
                'url'      => $link->url,
                'icon_url' => is_file(public_path(self::ICON_DIR . "/{$link->platform}.png"))
                    ? asset(self::ICON_DIR . "/{$link->platform}.png")
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * A site to build the preview/test UNSUBSCRIBE link from — nothing else.
     *
     * Since the branding is fixed in config, the choice no longer affects a
     * single visible string; it only decides which domain hosts the sample
     * opt-out link. Lowest active id, for a stable, repeatable default.
     */
    public function previewSite(): ?Site
    {
        return Site::query()->where('active', true)->orderBy('id')->first()
            ?? Site::query()->orderBy('id')->first();
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
    /**
     * Swap in this site's own hero image and links, if it has any.
     *
     * Applied AFTER render(), for two reasons. It runs on the finished strings,
     * so a site's link is never put through placeholder substitution and cannot
     * be mangled by a stray `{{ }}` in a URL. And it is the last word, so the
     * result is unambiguous: whatever the override sets, wins.
     *
     * ── What this may and may not touch ─────────────────────────────────────
     *
     * ONLY the hero image and the outbound links. No title, heading, button
     * LABEL, disclaimer or footer line is reachable from here — the override
     * model has no column for any of them. That is deliberate and is the whole
     * requirement: the email keeps its existing copy for every site, and only
     * where the reader is SENT changes.
     *
     * Two links are excluded by design. The unsubscribe URL is minted per
     * recipient from their own token, so an overridable one would let a site's
     * setting opt the wrong person out. The contact mailto is pinned to config.
     * Neither has a column on the override table, so neither can be reached.
     *
     * A site with no row, or a row whose fields are blank, returns the rendered
     * template untouched — which is what keeps every other site rendering
     * exactly what it rendered before this existed.
     *
     * @param  array<string, mixed>  $rendered
     * @return array<string, mixed>
     */
    private function withSiteOverrides(array $rendered, Site $site): array
    {
        $row = VerificationPromotionOverride::query()
            ->where('site_id', $site->id)
            ->first();

        if ($row === null) {
            return $rendered;
        }

        $rendered = array_replace($rendered, $row->overrides());

        // Footer links are re-POINTED, never replaced: each keeps the label the
        // template gave it and only its href changes. That is what stops this
        // feature from being able to alter a word of the email.
        $targets = $row->footerLinkTargets();

        if ($targets !== []) {
            $links = $rendered['footer_links'] ?? [];

            foreach ($targets as $i => $url) {
                // Only positions the template actually has. A stale target —
                // left behind after a footer link was deleted from the global
                // template — is ignored rather than appended as a link with no
                // label.
                if (isset($links[$i]) && is_array($links[$i])) {
                    $links[$i]['url'] = $url;
                }
            }

            $rendered['footer_links'] = $links;
        }

        return $rendered;
    }

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
            template: $this->withSiteOverrides($template->render($context), $site),
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
            socialLinks: $this->socialLinks(),
        );
    }
}
