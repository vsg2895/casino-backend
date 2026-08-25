<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single per-stream opt-out record: this email, on this site, unsubscribed
 * from this stream at this time. See the create_unsubscribes_table migration.
 */
class Unsubscribe extends Model
{
    /**
     * The email templates an unsubscribe can be attributed to.
     *
     * These name WHICH template the subscriber unsubscribed through, recorded for
     * detection/reporting. Send-gating itself is GLOBAL — see {@see hasAny()}:
     * any opt-out, of any type, stops all mail to that address — so the type is
     * about attribution, not about which stream stays alive.
     */
    public const string TYPE_SUBSCRIPTION = 'subscription';
    public const string TYPE_PROMOTION = 'promotion';
    public const string TYPE_VERIFY = 'verify';
    public const string TYPE_PROMOTION_AFTER_VERIFICATION = 'promotion_after_verification';

    /** @var list<string> */
    public const array TYPES = [
        self::TYPE_SUBSCRIPTION,
        self::TYPE_PROMOTION,
        self::TYPE_VERIFY,
        self::TYPE_PROMOTION_AFTER_VERIFICATION,
    ];

    protected $fillable = [
        'site_id',
        'email',
        'type',
        'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Absolute URL of the RFC 8058 one-click unsubscribe endpoint for a token.
     * Used in the List-Unsubscribe header. Resolves against APP_URL (the API
     * host), so it points at localhost during local development.
     */
    public static function oneClickUrl(string $token): string
    {
        return url('/api/v1/unsubscribe/' . $token);
    }

    /**
     * The same one-click endpoint, but on the SITE's own domain.
     *
     * Used by the verification email only. A List-Unsubscribe pointing at the API
     * host advertises a domain the recipient has never heard of, next to a message
     * whose whole purpose is asking them to trust a link — so that stream serves
     * the header from the brand domain instead.
     *
     * The target is a thin Next.js route handler (`src/app/api/unsubscribe/[token]`)
     * that POSTs straight through to {@see oneClickUrl()}'s endpoint. Nothing about
     * the opt-out itself moves: the same token, the same controller, the same
     * `unsubscribes` row. Only the hop in front of it is different.
     *
     * Kept SEPARATE from oneClickUrl() rather than replacing it, because the
     * subscription, promotion and post-verification streams must keep emitting the
     * exact header they emit today.
     */
    public static function siteOneClickUrl(Site $site, string $token): string
    {
        return $site->frontendBaseUrl() . '/api/unsubscribe/' . $token;
    }

    /** Record (or refresh) an opt-out idempotently. */
    public static function record(int $siteId, string $email, string $type): self
    {
        return static::updateOrCreate(
            ['site_id' => $siteId, 'email' => $email, 'type' => $type],
            ['unsubscribed_at' => now()],
        );
    }

    /** Whether the given address has opted out of the given stream on the site. */
    public static function has(int $siteId, string $email, string $type): bool
    {
        return static::where('site_id', $siteId)
            ->where('email', $email)
            ->where('type', $type)
            ->exists();
    }

    /**
     * Whether the address has opted out of ANY stream on the site.
     *
     * This is the send-gate every email checks: an opt-out recorded against any
     * template (subscription, verify, promotion, promotion-after-verification)
     * stops all further mail to that address. The per-type value is kept only so
     * the admin can see which template prompted the opt-out.
     */
    public static function hasAny(int $siteId, string $email): bool
    {
        return static::where('site_id', $siteId)
            ->where('email', $email)
            ->exists();
    }
}
