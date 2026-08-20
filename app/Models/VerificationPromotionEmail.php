<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single global "promotion after verification" template + its settings.
 *
 * Extends {@see SitePromotionEmail} on purpose: the template columns are
 * identical, so render(), the **bold** rich-text handling, COLOR_DEFAULTS and
 * unsubscribeUrl() are inherited unchanged — and, because
 * {@see \App\Services\PromotionEmailService::mailFor()} type-hints the parent,
 * this model flows through the existing mailable and Blade layout with no new
 * rendering code at all.
 *
 * What differs from the parent is scope and lifecycle:
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
        'button_color',
        'background_color',
        'heading_color',
        'text_color',
        'secondary_text_color',
        'muted_text_color',
        'accent_color',
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
            'preheader'         => 'Thanks for confirming your email — here is what we lined up for you.',
            'hero_image_url'    => null,
            'hero_url'          => '{{site_url}}',
            'top_button_text'   => 'See the offer',
            'heading'           => 'Thanks for confirming your email',
            'intro_text'        => 'Your subscription to **{{site_name}}** is now active, so here is the offer we promised.',
            'secondary_text'    => 'Every operator we list is reviewed before it appears. Terms and wagering requirements always apply.',
            'cta_button_text'   => 'Claim your offer',
            'disclaimer_text'   => '18+ only. Gambling carries real financial risk — please play responsibly.',
            'unsubscribe_label' => 'Unsubscribe',
            'active'            => false,
            'delay_minutes'     => 60,
            // The .env SendGrid key — usable with no further configuration.
            'provider'          => EmailSchedule::PROVIDER_SENDGRID_ENV,
        ];
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
