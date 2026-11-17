<?php

declare(strict_types=1);

namespace App\Perfume\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends PerfumeModel
{
    protected $table = 'perfume_categories';

    protected $fillable = [
        'name', 'slug', 'title', 'description', 'image_path', 'in_header', 'active', 'position',
        'meta_title', 'meta_description',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['in_header' => 'boolean', 'active' => 'boolean', 'position' => 'integer'];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'perfume_category_product', 'category_id', 'product_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
