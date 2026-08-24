<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single global "promotion after verification" template + its settings.
 *
 * Extends {@see SitePromotionEmail} for its lifecycle helpers and type identity
 * (so it still flows through {@see \App\Services\Mail\PromotionMailerFactory} and
 * the credential plumbing unchanged), but it OWNS its design: a richer, light
 * "thanks for subscribing — here's your welcome gift" layout with its own set of
 * editable components (eyebrow label, star-rating highlight box, responsible-
 * gambling notice, footer tagline + navigation links, affiliate disclosure,
 * copyright). It therefore overrides the field set, the colour palette, the
 * defaults and render(), and renders through its own Blade view
 * (`mail.promotion.after-verification`) via {@see \App\Mail\PostVerificationPromotionEmail}.
 *
 * Scope & lifecycle vs the parent:
 *  - ONE row for every site (there is no site_id), fetched via {@see current()};
 *  - it also carries the feature's settings (active, delay_minutes, transport).
 */
class VerificationPromotionEmail extends SitePromotionEmail
{
    protected $table = 'verification_promotion_emails';

    /** Upper bound for the delay: 30 days in minutes. See the update request. */
    public const int MAX_DELAY_MINUTES = 43200;

    /**
     * Transports this promotion may be sent with.
     *
     * Its own list rather than {@see EmailSchedule::PROVIDERS} because the two
     * features offer different SendGrid options on purpose:
     *  - here, SendGrid means the .env SENDGRID_API_KEY and needs no stored key;
     *  - a scheduled campaign still selects an admin-managed SendGrid key.
     *
     * PROVIDER_SENDGRID (stored key) is therefore absent here. A row saved with
     * it before this change still SENDS correctly — the factory keeps that
     * provider registered — it simply cannot be chosen again.
     *
     * @var list<string>
     */
    public const array PROVIDERS = [
        EmailSchedule::PROVIDER_SENDGRID_ENV,
        EmailSchedule::PROVIDER_MAILGUN,
        EmailSchedule::PROVIDER_SMTP,
    ];

    /** Body fields that support a light **bold** syntax when rendered to HTML. */
    public const array RICH_FIELDS = [
        'intro_text', 'secondary_text', 'disclaimer_text',
        'responsible_notice_text', 'footer_tagline', 'affiliate_disclosure_text',
    ];

    /**
     * Plain text/URL fields: placeholders are substituted but no markup is
     * allowed (Blade escapes them at render time).
     *
     * @var list<string>
     */
    private const array PLAIN_FIELDS = [
        'from_name', 'from_email', 'subject', 'preheader', 'hero_image_url', 'hero_url',
        'top_button_text', 'heading', 'cta_button_text', 'unsubscribe_label',
        'header_brand_text', 'eyebrow_text', 'confirmation_text',
        'highlight_text', 'copyright_text',
        // Footer legal/contact lines
        'reason_text', 'age_disclaimer_text', 'postal_address', 'contact_email',
        'email_preferences_label', 'email_preferences_url',
    ];

    /**
     * Every colour the light layout paints with, and the fallback used when a row
     * predates the column or an unsaved preview omits it. Overrides the parent's
     * dark palette: this template has its own header band, white body card and
     * dark footer, none of which the shared dark design had.
     */
    public const array COLOR_DEFAULTS = [
        'background_color'        => '#f4f5f7', // page canvas around the card
        'body_background_color'   => '#ffffff', // the white content card
        'header_color'            => '#059669', // header brand band
        'heading_color'           => '#111827', // the large title
        'text_color'              => '#374151', // body paragraphs
        'secondary_text_color'    => '#4b5563', // secondary paragraph
        'muted_text_color'        => '#6b7280', // fine print / notice
        'button_color'            => '#059669', // CTA + rating highlight
        'accent_color'            => '#059669', // eyebrow + links + unsubscribe
        'footer_background_color' => '#111827', // dark footer band
        // Raised for readability on the dark footer (was #9ca3af — too faint).
        'footer_text_color'       => '#b8bcba', // footer body copy
        'footer_link_color'       => '#d6dad8', // footer links (incl. unsubscribe)
    ];

    protected $fillable = [
        'from_name',
        'from_email',
        'subject',
        'preheader',
        'hero_image_url',
        'hero_url',
        'top_button_text',
        'heading',
        'intro_text',
        'secondary_text',
        'cta_button_text',
        'disclaimer_text',
        'unsubscribe_label',
        // New design components
        'header_brand_text',
        'eyebrow_text',
        'confirmation_text',
        'highlight_text',
        'offer_terms',
        'responsible_notice_text',
        'footer_tagline',
        'footer_links',
        'affiliate_disclosure_text',
        // Footer legal/contact lines
        'reason_text',
        'age_disclaimer_text',
        'postal_address',
        'contact_email',
        'email_preferences_label',
        'email_preferences_url',
        'copyright_text',
        // Palette
        'background_color',
        'body_background_color',
        'header_color',
        'heading_color',
        'text_color',
        'secondary_text_color',
        'muted_text_color',
        'button_color',
        'accent_color',
        'footer_background_color',
        'footer_text_color',
        'footer_link_color',
        // Settings
        'active',
        'delay_minutes',
        'provider',
        'sendgrid_key_id',
        'mailgun_key_id',
    ];

    protected function casts(): array
    {
        return [
            'active'          => 'boolean',
            'delay_minutes'   => 'integer',
            'sendgrid_key_id' => 'integer',
            'mailgun_key_id'  => 'integer',
            // Ordered list of {label,url} footer navigation links.
            'footer_links'    => 'array',
            // Ordered list of {label,value} offer "ticket" terms (Wagering, etc.).
            'offer_terms'     => 'array',
        ];
    }

    /**
     * The singleton row, created with defaults on first access.
     *
     * Mirrors how each site's template is materialised by
     * `Site::promotionEmailOrDefault()`, so the admin never has to "create"
     * anything before editing.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], self::defaults());
    }

    /**
     * Starting copy. Deliberately brand-neutral: ONE template serves subscribers
     * from every site, so the wording may not name a specific brand. The runtime
     * {{site_name}} / {{site_url}} placeholders (see the service context) fill in
     * the subscriber's own site, which is what keeps a single template correct
     * for all of them.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $domain = (string) config('services.sendgrid.from_domain', 'example.com');

        return [
            'from_name'         => '{{site_name}}',
            'from_email'        => 'offers@' . $domain,
            'subject'           => 'Your welcome offer at {{site_name}}',
            'preheader'         => 'Your welcome offer is ready — 100 free spins waiting inside.',
            'hero_image_url'    => null,
            'hero_url'          => '{{site_url}}',
            'top_button_text'   => null,
            // Talks to the reader about what they GET, not about the confirmation
            // that already happened (that fact lives in the confirmation strip).
            'heading'           => "Here's the offer we promised",
            'intro_text'        => 'We have lined up a special offer with one of our top-rated partners. Register and verify to claim it — no hassle, no delays.',
            // Concrete facts, not a vague "we reviewed it" claim: a real licence
            // and a real withdrawal time are what a reader actually weighs.
            'secondary_text'    => 'Licensed operator with withdrawals typically cleared within 24 hours and 24/7 support — the full terms are always on the offer page.',
            // Built from the offer variables so it stays specific for every offer:
            // {{bonus_amount}} is the ticket bonus, {{offer_brand}} the brand.
            'cta_button_text'   => 'Claim {{bonus_amount}} at {{offer_brand}}',
            'disclaimer_text'   => 'Wagering requirements and withdrawal caps are stated upfront on the offer page, so nothing surprises you later.',
            'unsubscribe_label' => 'Unsubscribe',

            // New design components
            'header_brand_text'         => '{{site_name}}',
            // Thin green strip at the very top — the confirmation fact, one line.
            'confirmation_text'         => '✓ Your email is confirmed — your welcome offer is unlocked',
            'eyebrow_text'              => 'Exclusive subscriber offer',
            // The "ticket": the bonus amount headline...
            'highlight_text'            => '100 Free Spins',
            // ...and the three terms a subscriber actually checks before clicking.
            'offer_terms'               => [
                ['label' => 'Wagering', 'value' => '40x'],
                ['label' => 'Min deposit', 'value' => 'None'],
                ['label' => 'Offer ends', 'value' => '7 days'],
            ],
            'responsible_notice_text'   => '**18+ · Gamble responsibly.** Gambling should stay entertainment, never a way to make money. Set a limit before you play and walk away when you reach it.',
            // "independent" and "finest" contradict each other — an independent
            // guide does not hand out superlatives, so the superlative is dropped.
            'footer_tagline'            => '{{site_name}} — A curated, independent guide to online casinos and current offers.',
            'footer_links'              => [
                ['label' => 'About', 'url' => '{{site_url}}/about'],
                ['label' => 'Contact', 'url' => '{{site_url}}/contact'],
                ['label' => 'Privacy Policy', 'url' => '{{site_url}}/privacy-policy'],
                ['label' => 'Responsible Gambling', 'url' => '{{site_url}}/responsible-gambling'],
            ],
            'affiliate_disclosure_text' => 'Some links in this email earn us a commission, which never influences a rating.',

            // Reason-for-receipt: reminds the reader they opted in, so they reach
            // for Unsubscribe instead of the Spam button.
            'reason_text'               => "You're getting this because you confirmed your subscription at {{site_domain}}.",
            // The age disclaimer itself — a Responsible Gambling link is NOT a
            // substitute for stating it in the email.
            'age_disclaimer_text'       => '18+ only. Gambling can be addictive — play responsibly.',
            // CAN-SPAM requires a real postal address; Gmail/Outlook weigh it for
            // inbox placement. Replace with the company's registered address.
            'postal_address'            => '123 Example Street, City 00000, Country',
            // A MONITORED mailbox that accepts replies — never no-reply@ / promo@.
            'contact_email'             => 'info@{{site_domain}}',
            // Lets a reader cut back instead of leaving entirely.
            'email_preferences_label'   => 'Email preferences',
            'email_preferences_url'     => '{{site_url}}/email-preferences',
            // "All rights reserved" is empty text with no legal effect — dropped.
            'copyright_text'            => '© {{year}} {{site_name}}',

            ...self::COLOR_DEFAULTS,

            'active'            => false,
            'delay_minutes'     => 60,
            // The .env SendGrid key — usable with no further configuration.
            'provider'          => EmailSchedule::PROVIDER_SENDGRID_ENV,
        ];
    }

    /**
     * Resolve this template into render-ready values for the Blade view.
     *
     * Placeholders ({{site_name}}, {{site_url}}, {{email}}, {{year}},
     * {{unsubscribe_url}}) are substituted everywhere, PLUS two derived offer
     * variables — {{bonus_amount}} (the ticket bonus) and {{offer_brand}} (the
     * brand) — so labels like the CTA stay specific for every offer. RICH_FIELDS
     * additionally get HTML-escaped and a minimal **bold** → <strong> conversion
     * so admins cannot inject markup. Plain/URL fields are left for Blade to
     * escape. `footer_links` / `offer_terms` return as arrays with placeholders
     * substituted — Blade escapes both when it emits them.
     *
     * @param  array<string, string>  $context
     * @return array<string, mixed>
     */
    public function render(array $context): array
    {
        $replace = static function (string $value) use (&$context): string {
            foreach ($context as $key => $val) {
                $value = str_replace('{{' . $key . '}}', $val, $value);
                $value = str_replace('{{ ' . $key . ' }}', $val, $value);
            }

            return $value;
        };

        // Offer variables derived from THIS template's own fields (site
        // placeholders resolved first). Exposing them lets copy — above all the
        // CTA — be composed from the offer rather than hard-coded, so the label
        // stays specific for every offer, e.g. "Claim {{bonus_amount}} at
        // {{offer_brand}}". Added to $context (captured by reference above) before
        // the field loops so every field substitution can use them.
        $context['bonus_amount'] = trim($replace((string) $this->highlight_text));
        $context['offer_brand']  = trim($replace((string) $this->header_brand_text));

        $out = [];

        foreach (self::PLAIN_FIELDS as $field) {
            $out[$field] = $replace((string) $this->{$field});
        }

        foreach (self::RICH_FIELDS as $field) {
            $out[$field] = self::richToHtml($replace((string) $this->{$field}));
        }

        // Colours never take placeholders. Each falls back to the design default
        // so an unsaved preview — or a row written before these columns existed —
        // still renders a complete palette instead of emitting empty CSS.
        foreach (self::COLOR_DEFAULTS as $field => $default) {
            $value = trim((string) $this->{$field});
            $out[$field] = $value !== '' ? $value : $default;
        }

        // Footer navigation links: substitute placeholders in each label + url,
        // drop any entry missing either half. Left as raw strings — Blade escapes
        // them where they are emitted.
        $out['footer_links'] = collect($this->footer_links ?? [])
            ->map(fn ($link): array => [
                'label' => $replace((string) ($link['label'] ?? '')),
                'url'   => $replace((string) ($link['url'] ?? '')),
            ])
            ->filter(fn (array $link): bool => $link['label'] !== '' && $link['url'] !== '')
            ->values()
            ->all();

        // Offer "ticket" terms: each {label,value} with placeholders substituted,
        // dropping any entry missing either half. Blade escapes them at emit time.
        $out['offer_terms'] = collect($this->offer_terms ?? [])
            ->map(fn ($term): array => [
                'label' => $replace((string) ($term['label'] ?? '')),
                'value' => $replace((string) ($term['value'] ?? '')),
            ])
            ->filter(fn (array $term): bool => $term['label'] !== '' && $term['value'] !== '')
            ->values()
            ->all();

        return $out;
    }

    /** Escape HTML, then convert a minimal **bold** syntax to <strong>. */
    private static function richToHtml(string $value): string
    {
        $escaped = e($value);

        return (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
    }

    /**
     * Row id in the selected provider's own credential table, or null for SMTP —
     * the value {@see \App\Services\Mail\PromotionMailerFactory::resolve()} takes.
     *
     * Reuses EmailSchedule's provider→column map rather than repeating the
     * mapping, so adding a provider there covers this feature too.
     */
    public function credentialId(): ?int
    {
        $column = EmailSchedule::PROVIDER_CREDENTIAL_COLUMNS[$this->provider] ?? null;

        return $column === null ? null : $this->{$column};
    }

    public function sendgridKey(): BelongsTo
    {
        return $this->belongsTo(SendgridKey::class);
    }

    public function mailgunKey(): BelongsTo
    {
        return $this->belongsTo(MailgunKey::class);
    }

    /**
     * This table has no site_id — the parent's relation would query a column
     * that does not exist, so it is closed off explicitly.
     */
    public function site(): BelongsTo
    {
        throw new \LogicException('The verification promotion template is global and belongs to no site.');
    }
}
