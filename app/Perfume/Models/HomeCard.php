<?php

declare(strict_types=1);

namespace App\Perfume\Models;

class HomeCard extends PerfumeModel
{
    protected $table = 'perfume_home_cards';

    public const array KINDS = ['hero_tile', 'service'];

    protected $fillable = ['kind', 'number', 'title', 'text', 'link_label', 'url', 'image_path', 'active', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'position' => 'integer'];
    }
}
