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

    /**
     * The pseudo-country meaning "available everywhere".
     *
     * A casino attached to this row is listed under EVERY country, so an
     * operator states it once instead of ticking all 79. Created by
     * WorldwideCountrySeeder; the slug is the identity because slugs never
     * change here and the name is the operator's to edit.
     */
    public const string WORLDWIDE_SLUG = 'worldwide';

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
    /**
     * Reduce a country selection to what it actually means.
     *
     * A list containing Worldwide collapses to Worldwide alone: the wildcard
     * already covers every country, so the specific ones alongside it are
     * redundant rows that will drift out of step with it. Any other list is
     * returned unchanged apart from de-duplication.
     *
     * Returns plain ints so it can be handed straight to sync().
     *
     * @param  array<int|string>  $countryIds
     * @return list<int>
     */
    public static function collapseWorldwide(array $countryIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $countryIds)));

        $worldwideId = self::worldwideId();

        if ($worldwideId !== null && in_array($worldwideId, $ids, true)) {
            return [$worldwideId];
        }

        return $ids;
    }

    /** Is this the wildcard row? */
    public function isWorldwide(): bool
    {
        return $this->slug === self::WORLDWIDE_SLUG;
    }

    /**
     * The wildcard row's id, or null when the seeder has never been run.
     *
     * Deliberately NOT memoised. A function-static would live for the whole PHP
     * process, which is a request in FPM but is days inside a queue worker: a
     * worker started before the seeder ran would cache "no wildcard" and keep
     * that answer long after the row existed. It is one indexed lookup, called
     * at most once per listing, so the cache bought nothing worth that.
     *
     * Callers MUST handle null: on a database where WorldwideCountrySeeder has
     * not run there is no wildcard, and every query has to keep working.
     */
    public static function worldwideId(): ?int
    {
        $id = self::query()->where('slug', self::WORLDWIDE_SLUG)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
