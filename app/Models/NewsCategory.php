<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An editorial section for one site's news — "Licensing", "Industry", "Payments".
 *
 * Belongs to a site, unlike BonusCategory: a bonus type describes the offer and
 * means the same on every domain, while each site's editorial sections are its
 * own.
 *
 * The slug is generated once and never regenerated — it is in
 * `/news?category=…` and in the cache keys, so a rename must not move the
 * address.
 */
class NewsCategory extends Model
{
    protected $fillable = [
        'site_id',
        'name',
        'position',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'active'   => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $category): void {
            if (trim((string) $category->slug) === '') {
                $category->slug = Str::slug((string) $category->name);
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('name');
    }
}
