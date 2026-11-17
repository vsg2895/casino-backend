<?php

declare(strict_types=1);

namespace App\Perfume\Models;

class Page extends PerfumeModel
{
    protected $table = 'perfume_pages';

    protected $fillable = ['title', 'slug', 'body', 'active', 'position', 'meta_title', 'meta_description'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'position' => 'integer'];
    }
}
