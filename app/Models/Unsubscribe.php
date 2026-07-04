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
    /** Email streams a subscriber can opt out of independently. */
    public const string TYPE_SUBSCRIPTION = 'subscription';
    public const string TYPE_PROMOTION = 'promotion';

    /** @var list<string> */
    public const array TYPES = [self::TYPE_SUBSCRIPTION, self::TYPE_PROMOTION];

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
}
