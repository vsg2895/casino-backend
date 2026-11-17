<?php

declare(strict_types=1);

namespace App\Perfume\Models;

class NavItem extends PerfumeModel
{
    protected $table = 'perfume_nav_items';

    public const array LOCATIONS = ['header', 'footer'];

    protected $fillable = ['location', 'group_label', 'label', 'url', 'active', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'position' => 'integer'];
    }
}
