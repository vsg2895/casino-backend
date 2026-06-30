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
    ];

    /** The unsubscribe token is a secret — never expose it in any response. */
    protected $hidden = [
        'unsubscribe_token',
    ];

    protected static function booted(): void
    {
        // Every subscriber gets a stable, unguessable one-click unsubscribe token.
        static::creating(function (Newsletter $newsletter): void {
            if (empty($newsletter->unsubscribe_token)) {
                $newsletter->unsubscribe_token = self::generateUnsubscribeToken();
            }
        });
    }

    public static function generateUnsubscribeToken(): string
    {
        return Str::random(64);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
