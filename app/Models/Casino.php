<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CasinoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'active',
    ];

    protected function casts(): array
    {
        return [
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

    public function specialOffers(): HasMany
    {
        return $this->hasMany(SpecialOffer::class)->orderBy('sort_order');
    }

    public function featuredSpecialOffer(): BelongsTo
    {
        return $this->belongsTo(SpecialOffer::class, 'featured_special_offer_id');
    }
}
