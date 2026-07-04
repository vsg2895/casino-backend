<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Newsletter extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'site_id',
        'email',
        'unsubscribe_token',
        'promotion_unsubscribe_token',
    ];

    /** Unsubscribe tokens are secrets — never expose them in any response. */
    protected $hidden = [
        'unsubscribe_token',
        'promotion_unsubscribe_token',
    ];

    protected static function booted(): void
    {
        // Every subscriber gets a stable, unguessable one-click unsubscribe token
        // per email stream (subscription + promotion) so each can be opted out
        // independently without ever exposing the address in the URL.
        static::creating(function (Newsletter $newsletter): void {
            if (empty($newsletter->unsubscribe_token)) {
                $newsletter->unsubscribe_token = self::generateUnsubscribeToken();
            }
            if (empty($newsletter->promotion_unsubscribe_token)) {
                $newsletter->promotion_unsubscribe_token = self::generateUnsubscribeToken();
            }
        });
    }

    public static function generateUnsubscribeToken(): string
    {
        return Str::random(64);
    }

    /** The opaque unsubscribe token for a given stream (Unsubscribe::TYPE_*). */
    public function unsubscribeTokenFor(string $type): string
    {
        return $type === Unsubscribe::TYPE_PROMOTION
            ? (string) $this->promotion_unsubscribe_token
            : (string) $this->unsubscribe_token;
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
