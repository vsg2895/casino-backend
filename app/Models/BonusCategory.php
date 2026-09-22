<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * A kind of bonus — "Special Offers", "No Deposit", "Free Spins".
 *
 * ONE row drives two surfaces: an entry under the Bonus menu, and a section on
 * the home page listing the offers filed under it. That is deliberate. A menu
 * maintained by hand beside a page built by hand drift apart within weeks, and
 * the first symptom is a menu item leading to a section that no longer exists.
 *
 * Global rather than per-site, like Category: a bonus type is a property of the
 * offer, not of the domain displaying it. Whether a site publishes the Bonus
 * area at all is `sites.bonus_enabled`.
 */
class BonusCategory extends Model
{
    use HasSlug;

    protected $fillable = [
        'name',
        'description',
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

    /**
     * The slug is in the public URL and in every cache key, so it is generated
     * once and never regenerated — renaming a category is a rename, not a new
     * address.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }

    public function specialOffers(): HasMany
    {
        return $this->hasMany(SpecialOffer::class);
    }

    /** Shown on the site. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** The order the menu and the home page both render in. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('name');
    }
}
