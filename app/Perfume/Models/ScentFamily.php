<?php

declare(strict_types=1);

namespace App\Perfume\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ScentFamily extends PerfumeModel
{
    protected $table = 'perfume_scent_families';

    protected $fillable = ['name', 'slug', 'position'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'scent_family_id');
    }
}
