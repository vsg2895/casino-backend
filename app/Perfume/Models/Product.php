<?php

declare(strict_types=1);

namespace App\Perfume\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends PerfumeModel
{
    protected $table = 'perfume_products';

    public const array GENDERS = ['women', 'men', 'unisex'];

    /** Concentration keys → display names (the catalog's "Type" filter). */
    public const array TYPES = [
        'edp'     => 'Eau de parfum',
        'edt'     => 'Eau de toilette',
        'parfum'  => 'Parfum',
        'edc'     => 'Eau de cologne',
        'extrait' => 'Extrait de parfum',
    ];

    public const array LAYERS = ['top', 'heart', 'base'];

    protected $fillable = [
        'brand_id', 'perfumer_id', 'scent_family_id', 'name', 'slug', 'sku', 'gender', 'type', 'year',
        'country', 'description', 'tint', 'rating', 'reviews_count', 'is_new', 'is_on_sale', 'active',
        'position', 'meta_title', 'meta_description',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year'          => 'integer',
            'rating'        => 'float',
            'reviews_count' => 'integer',
            'is_new'        => 'boolean',
            'is_on_sale'    => 'boolean',
            'active'        => 'boolean',
            'position'      => 'integer',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function perfumer(): BelongsTo
    {
        return $this->belongsTo(Perfumer::class, 'perfumer_id');
    }

    public function scentFamily(): BelongsTo
    {
        return $this->belongsTo(ScentFamily::class, 'scent_family_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'perfume_category_product', 'product_id', 'category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id')->orderBy('position')->orderBy('id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_id')->orderBy('position')->orderBy('id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ProductNote::class, 'product_id')->orderBy('position')->orderBy('id');
    }

    /** Active, with an active brand — what the storefront may show. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('perfume_products.active', true)
            ->whereHas('brand', fn (Builder $b) => $b->where('active', true));
    }

    /** The relations every storefront representation needs. */
    public static function withStorefront(): Builder
    {
        return self::query()->with([
            'brand', 'perfumer', 'scentFamily', 'categories', 'variants', 'images', 'notes.scentNote',
        ]);
    }
}
