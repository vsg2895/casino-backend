<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * A country a casino accepts players from.
 *
 * Global master data, exactly like {@see Category}: the attachment lives in the
 * `casino_country` pivot and carries no per-site payload, because whether a
 * casino accepts a country is a property of the casino, not of the domain
 * displaying it.
 *
 * Not every row is a sovereign country. The grid also carries a Europe-wide
 * entry and an "Arab" entry, which behave identically but have no ISO code —
 * hence `code` being nullable.
 */
class Country extends Model
{
    use HasSlug;

    protected $fillable = [
        'continent_id',
        'name',
        'code',
        'image_path',
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

    /** The slug is part of every public URL and cache key, so it never changes. */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }

    public function continent(): BelongsTo
    {
        return $this->belongsTo(Continent::class);
    }

    public function casinos(): BelongsToMany
    {
        return $this->belongsToMany(Casino::class);
    }

    /**
     * Display order within a continent: explicit position, then name.
     *
     * Matches the index on (continent_id, position, name), so the grid's query
     * is served without a filesort.
     *
     * @param  Builder<Country>  $query
     * @return Builder<Country>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('name');
    }

    /**
     * @param  Builder<Country>  $query
     * @return Builder<Country>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
