<?php

declare(strict_types=1);

namespace App\Perfume\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends PerfumeModel
{
    protected $table = 'perfume_brands';

    protected $fillable = [
        'name', 'slug', 'description', 'image_path', 'country', 'featured', 'active', 'position',
        'meta_title', 'meta_description',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['featured' => 'boolean', 'active' => 'boolean', 'position' => 'integer'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'brand_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
