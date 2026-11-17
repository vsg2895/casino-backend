<?php

declare(strict_types=1);

namespace App\Perfume\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Perfumer extends PerfumeModel
{
    protected $table = 'perfume_perfumers';

    protected $fillable = ['name', 'slug', 'description', 'image_path', 'meta_title', 'meta_description'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'perfumer_id');
    }

    /**
     * Alphabetical — this is the one perfume table with no `position`
     * column, because a nose has no running order the way a menu does.
     * Without this override the inherited scope sorts by a column that does
     * not exist, which is a 500 on the admin's list.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name')->orderBy('id');
    }
}
