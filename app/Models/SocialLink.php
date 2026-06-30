<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialLink extends Model
{
    /** @use HasFactory<\Database\Factories\SocialLinkFactory> */
    use HasFactory;

    /**
     * Supported platforms. The public footer maps each to an icon, so new
     * platforms must be added here AND given an icon on the sites.
     *
     * @var list<string>
     */
    public const PLATFORMS = [
        'facebook',
        'twitter',
        'instagram',
        'youtube',
        'tiktok',
        'telegram',
        'discord',
        'linkedin',
        'twitch',
        'reddit',
    ];

    protected $fillable = [
        'site_id',
        'platform',
        'label',
        'url',
        'sort_order',
        'active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active'     => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Active links first, ordered by priority then id (stable). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
