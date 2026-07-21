<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\Site;

/**
 * Resolves the "From" address for public (SendGrid) verification emails.
 *
 * Two modes:
 *  - FORCED verified sender (current production): when
 *    config('mail.public_from_address') is set, every site's verification email
 *    is sent from that single SendGrid-authenticated mailbox (e.g.
 *    noreply@winpalack.com) so it passes SPF/DKIM regardless of the subscribing
 *    site. The display name still reflects the site (template from_name).
 *  - PER-SITE domain (later, once each domain is authenticated in SendGrid):
 *    leave that config empty and the From domain is derived from the site —
 *      1. an explicit slug→domain override (config('mail.site_from_domains'))
 *      2. the site's own registered `domain`
 *      3. the config('mail.public_from_domain') fallback
 */
final class SiteSender
{
    /** The From address for a site's public verification email. */
    public static function verificationAddress(Site $site): string
    {
        // Production: a single SendGrid-verified sender for ALL sites.
        $forced = trim((string) config('mail.public_from_address', ''));
        if ($forced !== '') {
            return $forced;
        }

        $local = trim((string) config('mail.public_from_local_part', 'noreply')) ?: 'noreply';

        return $local . '@' . self::domainFor($site);
    }

    public static function domainFor(Site $site): string
    {
        $overrides = (array) config('mail.site_from_domains', []);

        $domain = $overrides[$site->slug]
            ?? ($site->domain ?: (string) config('mail.public_from_domain', 'example.com'));

        return ltrim((string) $domain, '@');
    }
}
