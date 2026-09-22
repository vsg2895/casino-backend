<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A visual grouping of categories on the forum index. */
class ForumSection extends Model
{
    protected $fillable = ['site_id', 'name', 'description', 'position', 'active'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'active'   => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $section): void {
            if (trim((string) $section->slug) === '') {
                $section->slug = Str::slug((string) $section->name);
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(ForumCategory::class)->orderBy('position')->orderBy('name');
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
