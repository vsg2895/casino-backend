<?php

declare(strict_types=1);

namespace App\Perfume\Models;

class Store extends PerfumeModel
{
    protected $table = 'perfume_stores';

    protected $fillable = ['name', 'address', 'hours', 'map_url', 'active', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'position' => 'integer'];
    }
}
