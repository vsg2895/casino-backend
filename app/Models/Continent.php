<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * A heading on the countries grid — Europe, America, Africa, Asia,
 * Australia & Oceania.
 *
 * Its own table rather than a string column on `countries` because it is
 * rendered as a group with its own explicit ordering, and a free-text column
 * would let two spellings of the same continent split one group into two.
 */
class Continent extends Model
{
    use HasSlug;

    protected $fillable = [
        'name',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /** The slug is part of every public URL and cache key, so it never changes. */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }

    public function countries(): HasMany
    {
        return $this->hasMany(Country::class)->ordered();
    }

    /**
     * Display order: explicit position first, then name as the tie-breaker.
     *
     * @param  Builder<Continent>  $query
     * @return Builder<Continent>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('name');
    }
}
