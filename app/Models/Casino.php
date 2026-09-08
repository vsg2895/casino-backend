<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CasinoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Casino extends Model
{
    /** @use HasFactory<CasinoFactory> */
    use HasFactory, HasSlug, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'image_path',
        'banner_image',
        'bonuses',
        'affiliate_url',
        'description',
        'rating',
        'sort_order',
        'featured_special_offer_id',
        'meta_title',
        'meta_description',
        'canonical_url',
        'noindex',
        'bonuses_intro',
        'reviewed_at',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'noindex' => 'boolean',
            'reviewed_at' => 'date',
            'rating'     => 'integer',
            'sort_order' => 'integer',
            'active'     => 'boolean',
        ];
    }

    /**
     * The slug is generated from the name only when one is not supplied, and is
     * never regenerated afterwards.
     *
     * `doNotGenerateSlugsOnUpdate()` is what makes the slug ADMIN-OWNED rather
     * than immutable: an admin may edit it deliberately (validated for format
     * and uniqueness in {@see \App\Http\Requests\Admin\UpdateCasinoRequest}),
     * but simply renaming a casino must never move its public URL out from under
     * the pages already indexed against it.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class)
            ->withPivot(['affiliate_url', 'position', 'featured', 'active'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * Countries this casino accepts players from.
     *
     * No pivot payload, for the same reason categories have none: the fact is a
     * property of the casino itself and does not vary by site.
     */
    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class);
    }

    /**
     * The factual profile — licence, payments, support, safer-play tools.
     *
     * A separate row rather than columns here, so the hot listing query that
     * reads `casinos` through `casino_site` never carries fields only the detail
     * page uses. See the migration for the full reasoning.
     */
    public function detail(): HasOne
    {
        return $this->hasOne(CasinoDetail::class);
    }

    public function specialOffers(): HasMany
    {
        return $this->hasMany(SpecialOffer::class)->orderBy('sort_order');
    }

    /**
     * Visitor-written reviews. Unscoped by site and unscoped by status — every
     * caller narrows it, and a relation that silently hid rows would make the
     * admin's moderation queue impossible to build from it.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(CasinoReview::class);
    }

    public function featuredSpecialOffer(): BelongsTo
    {
        return $this->belongsTo(SpecialOffer::class, 'featured_special_offer_id');
    }
}
